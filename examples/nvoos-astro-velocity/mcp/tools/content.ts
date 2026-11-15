// ---------------------------------------------------------------------------
// content.ts — nvoos-astro-mcp content tool slice (Proposal 107, DECISION-1).
//
// Eight tools over the content/pages + content/posts collections: reads are
// open; every write demands the manager session cookie (fail-closed, the
// cms_* pattern) and the backend enforces the approval state machine
// (draft/pending → publish requires publish_posts — a state the submitting
// actor cannot flip). Successful writes purge the matching route-cache tags
// through the same-process /internal/revalidate endpoint (104 Q3) when a
// revalidate token is configured — purge failures never fail the tool call.
//
// G14: writes ride a per-session quota window so an agent loop cannot
// runaway-generate content (env-tunable, default 30 writes / 10 min).
// ---------------------------------------------------------------------------

import type { McpServer } from '@modelcontextprotocol/server';
import { createHash } from 'node:crypto';
import { z } from 'zod';
import { joinPath } from '../../src/lib/commerce/client';
import {
	parsePortableText,
	portableTextSchema,
	sanitizeContentHtml,
} from '../../src/lib/content/portable-text';
import { previewTtlMs, signPreviewToken } from '../../src/lib/content/preview';
import { getMcpRequestContext } from '../context';
import { storefrontOrigin } from './ops';
import {
	DESTRUCTIVE_WRITE_TOOL,
	IDEMPOTENT_WRITE_TOOL,
	READ_ONLY_TOOL,
	WRITE_TOOL,
	envValue,
	errorResult,
	resolveApiBase,
	text,
} from './util';

const CONTENT_TYPES = ['page', 'post'] as const;
const typeSchema = z.enum(CONTENT_TYPES);

const MANAGER_REQUIRED = 'content writes require a manager session cookie';
const NO_BASE = 'commerce base is not configured (set NVOOS_API_BASE or NVOOS_SSR_API_ORIGIN)';

/** Accepted statuses — the WP-native approval ladder (107 §2.1). */
const STATUS_VALUES = ['draft', 'pending', 'publish', 'future'] as const;

/** Fields the content slice accepts on create/update (107 §5). */
const contentFieldsObject = z.object({
	title: z.string().min(1).max(300),
	excerpt: z.string().max(600).optional(),
	content: z.string().max(100_000).optional(),
	blocks: portableTextSchema.optional(),
	seoTitle: z.string().max(300).optional(),
	seoDescription: z.string().max(500).optional(),
	status: z.enum(STATUS_VALUES).optional(),
	publishAt: z.string().max(64).optional(),
	categoryId: z.number().int().positive().optional(),
	submittedBy: z.string().max(120).optional(),
});

/** A create needs a title, content, or blocks — the refined form (ZodEffects,
 *  so no .extend()/.partial() — extend the OBJECT form for those). */
const contentFieldsSchema = contentFieldsObject.refine(
	(data) => Boolean(data.title || data.content || data.blocks),
	{ message: 'a document needs a title, content, or blocks' }
);

/** Create schema — the refined requirement plus type + draft default. */
const contentCreateSchema = contentFieldsObject
	.extend({
		type: typeSchema,
		status: z.enum(STATUS_VALUES).default('draft'),
	})
	.refine((data) => Boolean(data.title || data.content || data.blocks), {
		message: 'a document needs a title, content, or blocks',
	});

/** Update schema — every field optional, type + id required. */
const contentUpdateSchema = contentFieldsObject
	.partial()
	.extend({ type: typeSchema, id: z.number().int().positive() });

function managerCookie(): string | null {
	return getMcpRequestContext()?.cookie ?? null;
}

// ---------------------------------------------------------------------------
// G14 — per-session write quota (env-tunable window).
// ---------------------------------------------------------------------------

const DEFAULT_WRITE_MAX = 30;
const DEFAULT_WINDOW_MS = 10 * 60 * 1000;

function quotaLimits(): { max: number; windowMs: number } {
	const max = Number(envValue('NVOOS_MCP_CONTENT_WRITE_MAX'));
	const windowMs = Number(envValue('NVOOS_MCP_CONTENT_WRITE_WINDOW_MS'));
	return {
		max: Number.isFinite(max) && max > 0 ? max : DEFAULT_WRITE_MAX,
		windowMs: Number.isFinite(windowMs) && windowMs > 0 ? windowMs : DEFAULT_WINDOW_MS,
	};
}

const writeWindows = new Map<string, { count: number; windowStart: number }>();

/** Hash the session cookie so raw credentials never sit in the quota map. */
function sessionKey(cookie: string): string {
	return createHash('sha256').update(cookie).digest('hex');
}

function quotaExceeded(cookie: string): string | null {
	const { max, windowMs } = quotaLimits();
	const now = Date.now();
	const key = sessionKey(cookie);
	const window = writeWindows.get(key);
	if (!window || window.windowStart + windowMs <= now) {
		writeWindows.set(key, { count: 1, windowStart: now });
		return null;
	}
	window.count += 1;
	if (window.count > max) {
		return `content write quota exceeded (${max} writes per ${Math.round(windowMs / 60000)} min)`;
	}
	return null;
}

// ---------------------------------------------------------------------------
// Post-write cache purge (104 Q3) — best-effort, never fails the tool call.
// ---------------------------------------------------------------------------

async function purgeContentTags(tags: string[]): Promise<string> {
	const token = envValue('NVOOS_REVALIDATE_TOKEN');
	const ssrOrigin = envValue('NVOOS_SSR_API_ORIGIN');
	if (!token || !ssrOrigin) {
		return 'skipped (revalidation disabled)';
	}
	try {
		const res = await fetch(`${ssrOrigin.replace(/\/+$/, '')}/internal/revalidate/`, {
			method: 'POST',
			headers: {
				authorization: `Bearer ${token}`,
				'content-type': 'application/json',
			},
			body: JSON.stringify({ tags }),
		});
		return res.ok ? `purged [${tags.join(', ')}]` : `purge skipped (status ${res.status})`;
	} catch (error) {
		return `purge failed (${(error as Error).message})`;
	}
}

function contentTags(type: string, id: number): string[] {
	return ['content', `content:${type}`, `content:${type}:${id}`, 'sitemap'];
}

// ---------------------------------------------------------------------------
// Shared request plumbing (the cms.ts pattern).
// ---------------------------------------------------------------------------

async function readJson(res: Response): Promise<unknown> {
	try {
		return await res.json();
	} catch {
		return null;
	}
}

function statusError(method: string, url: string, status: number, payload: unknown) {
	return errorResult(`content ${method} failed: backend responded ${status}`, {
		error: 'backend_error',
		method,
		url,
		status,
		payload,
	});
}

type WriteMethod = 'POST' | 'PATCH' | 'DELETE';

async function writeRequest(method: WriteMethod, path: string, body: Record<string, unknown> | null) {
	const cookie = managerCookie();
	if (!cookie) {
		return errorResult(MANAGER_REQUIRED, { error: 'manager_session_required' });
	}
	const quotaMessage = quotaExceeded(cookie);
	if (quotaMessage) {
		return errorResult(quotaMessage, { error: 'quota_exceeded' });
	}
	const base = resolveApiBase();
	if (!base) {
		return errorResult(NO_BASE, { error: 'no_commerce_base' });
	}
	const url = joinPath(base, path);
	let res: Response;
	try {
		res = await fetch(url, {
			method,
			headers: {
				accept: 'application/json',
				'content-type': 'application/json',
				cookie,
			},
			body: body ? JSON.stringify(body) : undefined,
		});
	} catch (error) {
		return errorResult(`content ${method} failed: ${(error as Error).message}`, {
			error: 'request_failed',
		});
	}
	const payload = await readJson(res);
	if (!res.ok) {
		return statusError(method, url, res.status, payload);
	}

	// Post-write tag purge (104) — reported, never fatal.
	const doc = payload as { id?: number; type?: string } | null;
	const type = doc?.type ?? (path.startsWith('content/pages') ? 'page' : 'post');
	const id = doc?.id;
	const purge = id ? await purgeContentTags(contentTags(type, id)) : 'skipped (no id)';
	const wrapped = { ...(payload as Record<string, unknown>), _purge: purge };
	return text(JSON.stringify(wrapped, null, 2), wrapped);
}

// ---------------------------------------------------------------------------
// Preview tokens (107 §3.2, D3) — minting lives in src/lib/content/preview.ts
// (shared with the /preview route).
// ---------------------------------------------------------------------------

/** Shared by the /preview route — see src/lib/content/preview.ts. */

// ---------------------------------------------------------------------------
// Registration.
// ---------------------------------------------------------------------------

export function registerContentTools(server: McpServer): void {
	server.registerTool(
		'content_list',
		{
			title: 'List content documents',
			description:
				'List the content/pages or content/posts collection (paginated envelope). Read-only; the status filter (draft/pending/future/any) requires the manager session cookie.',
			annotations: READ_ONLY_TOOL,
			inputSchema: z.object({
				type: typeSchema,
				page: z.number().int().positive().optional(),
				limit: z.number().int().positive().max(100).optional(),
				status: z.enum([...STATUS_VALUES, 'any']).optional(),
			}),
		},
		async (args) => {
			const base = resolveApiBase();
			if (!base) {
				return errorResult(NO_BASE, { error: 'no_commerce_base' });
			}
			const wantsDrafts = args.status !== undefined && args.status !== 'publish';
			const cookie = managerCookie();
			if (wantsDrafts && !cookie) {
				return errorResult(
					'content_list with a non-publish status filter requires a manager session cookie',
					{ error: 'manager_session_required' }
				);
			}
			const q = new URLSearchParams();
			if (args.page && args.page > 1) {
				q.set('page', String(args.page));
			}
			if (args.limit) {
				q.set('limit', String(args.limit));
			}
			if (args.status && args.status !== 'publish') {
				q.set('status', args.status);
			}
			const suffix = q.toString() ? `?${q.toString()}` : '';
			const url = `${joinPath(base, `content/${args.type}s`)}${suffix}`;
			let res: Response;
			try {
				res = await fetch(url, {
					headers: { accept: 'application/json', ...(cookie ? { cookie } : {}) },
				});
			} catch (error) {
				return errorResult(`content_list failed: ${(error as Error).message}`, {
					error: 'request_failed',
				});
			}
			const payload = (await readJson(res)) as Record<string, unknown> | null;
			if (!res.ok || !payload || !Array.isArray(payload.docs)) {
				return errorResult(`content_list failed: backend responded ${res.status}`, {
					error: 'backend_error',
					status: res.status,
				});
			}
			const summarized = {
				collection: `content/${args.type}s`,
				docs: payload.docs,
				totalDocs: payload.totalDocs,
				limit: payload.limit,
				totalPages: payload.totalPages,
				page: payload.page,
				hasNextPage: payload.hasNextPage,
				hasPrevPage: payload.hasPrevPage,
			};
			return text(JSON.stringify(summarized, null, 2), summarized);
		}
	);

	server.registerTool(
		'content_get',
		{
			title: 'Get content document',
			description:
				'Fetch one content document by id (drafts/pending need the manager cookie). Read-only.',
			annotations: READ_ONLY_TOOL,
			inputSchema: z.object({
				type: typeSchema,
				id: z.number().int().positive(),
			}),
		},
		async (args) => {
			const base = resolveApiBase();
			if (!base) {
				return errorResult(NO_BASE, { error: 'no_commerce_base' });
			}
			const cookie = managerCookie();
			const url = joinPath(base, `content/${args.type}s/${args.id}`);
			let res: Response;
			try {
				res = await fetch(url, {
					headers: { accept: 'application/json', ...(cookie ? { cookie } : {}) },
				});
			} catch (error) {
				return errorResult(`content_get failed: ${(error as Error).message}`, {
					error: 'request_failed',
				});
			}
			const payload = await readJson(res);
			if (!res.ok || payload === null || payload === undefined) {
				return errorResult(`content_get failed: backend responded ${res.status}`, {
					error: 'backend_error',
					status: res.status,
					type: args.type,
					id: args.id,
				});
			}
			return text(JSON.stringify(payload, null, 2), payload);
		}
	);

	server.registerTool(
		'content_create',
		{
			title: 'Create content document',
			description:
				'Create a page or post (draft/pending by default; publishing is a separate capability-gated transition the backend enforces). Manager session required.',
			annotations: WRITE_TOOL,
			inputSchema: contentCreateSchema,
		},
		async (args) => {
			const { type, categoryId, ...fields } = args;
			const body: Record<string, unknown> = { ...fields };
			if (categoryId !== undefined) {
				body.category = { id: categoryId };
			}
			return writeRequest('POST', `content/${type}s`, body);
		}
	);

	server.registerTool(
		'content_update',
		{
			title: 'Update content document',
			description:
				'Update one page/post by id — fields and/or a status transition (publish/future requires the backend publish capability). Manager session required.',
			annotations: WRITE_TOOL,
			inputSchema: contentUpdateSchema,
		},
		async (args) => {
			const { type, id, categoryId, ...fields } = args;
			const body: Record<string, unknown> = { ...fields };
			if (categoryId !== undefined) {
				body.category = { id: categoryId };
			}
			return writeRequest('PATCH', `content/${type}s/${id}`, body);
		}
	);

	server.registerTool(
		'content_delete',
		{
			title: 'Delete content document',
			description:
				'Delete one page/post by id. Destructive — the backend removes the document permanently. Manager session required.',
			annotations: DESTRUCTIVE_WRITE_TOOL,
			inputSchema: z.object({
				type: typeSchema,
				id: z.number().int().positive(),
			}),
		},
		async (args) => writeRequest('DELETE', `content/${args.type}s/${args.id}`, null)
	);

	server.registerTool(
		'content_preview_url',
		{
			title: 'Get content preview URL',
			description:
				'Mint a short-lived, signed preview URL for a page/post (drafts included). The URL exchanges the token for a preview cookie and redirects to the canonical route. Read-only.',
			annotations: READ_ONLY_TOOL,
			inputSchema: z.object({
				type: typeSchema,
				id: z.number().int().positive(),
			}),
		},
		async (args) => {
			const expiry = Date.now() + previewTtlMs();
			const token = signPreviewToken(args.type, args.id, expiry);
			const url = `${storefrontOrigin()}/preview/${args.type}/${args.id}/?token=${encodeURIComponent(token)}`;
			return text(`Preview URL (valid ${Math.round(previewTtlMs() / 60000)} min): ${url}`, {
				url,
				expiresAt: new Date(expiry).toISOString(),
			});
		}
	);

	server.registerTool(
		'content_restore',
		{
			title: 'Restore content revision',
			description:
				'Restore a previous revision of a page/post (WP semantics: the restore itself becomes a new revision — history is never lost). Manager session required.',
			annotations: IDEMPOTENT_WRITE_TOOL,
			inputSchema: z.object({
				type: typeSchema,
				id: z.number().int().positive(),
				revision: z.number().int().positive(),
			}),
		},
		async (args) =>
			writeRequest('POST', `content/${args.type}s/${args.id}/restore`, {
				revision: args.revision,
			})
	);

	server.registerTool(
		'content_validate',
		{
			title: 'Validate content payload',
			description:
				'Dry-run validation of a content payload: block schema, size caps, raw-HTML sanitization and link-safety. Returns the normalized payload it would write. Never writes. Read-only.',
			annotations: READ_ONLY_TOOL,
			inputSchema: contentFieldsSchema,
		},
		async (args) => {
			// Two gates, one shape: blocks parse loudly (schema + link-href walk —
			// unsafe markDef hrefs are REJECTED, not scrubbed) and the raw-HTML
			// field passes the sanitize-html allowlist (scripts, inline handlers
			// and javascript:/data: hrefs stripped).
			try {
				const blocks = args.blocks ? parsePortableText(args.blocks) : undefined;
				const sanitizedContent = args.content
					? sanitizeContentHtml(args.content)
					: undefined;
				const normalized = {
					title: args.title,
					excerpt: args.excerpt,
					content: sanitizedContent,
					blocks,
					seoTitle: args.seoTitle,
					seoDescription: args.seoDescription,
					status: args.status,
					publishAt: args.publishAt,
					submittedBy: args.submittedBy,
				};
				return text('Payload is valid.', { valid: true, normalized });
			} catch (error) {
				return errorResult(`content_validate failed: ${(error as Error).message}`, {
					error: 'invalid_payload',
				});
			}
		}
	);
}
