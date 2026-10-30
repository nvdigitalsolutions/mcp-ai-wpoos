// ---------------------------------------------------------------------------
// cms.ts — nvoos-astro-mcp CMS tool slice (Proposal 095 §3.3, Phase 3/4.3).
//
// Thin read/write wrappers over the NV commerce contract. Reads are open;
// writes demand the manager session cookie carried in the per-request
// context (the write auth gate runs upstream in the BFF) and forward it to
// the backend verbatim. Backend 401/404s surface as typed error results.
// ---------------------------------------------------------------------------

import type { McpServer } from '@modelcontextprotocol/server';
import { z } from 'zod';
import {
	MAX_LIMIT,
	buildCollectionQuery,
	joinPath,
} from '../../src/lib/commerce/client';
import type { SortValue, WhereClause } from '../../src/lib/commerce/types';
import { getMcpRequestContext } from '../context';
import { errorResult, resolveApiBase, text } from './util';

/** Collections the CMS slice may touch — an allowlist, never a passthrough. */
const COLLECTIONS = [
	'products',
	'categories',
	'brands',
	'colors',
	'media',
	'promotions',
	'promocodes',
] as const;

const collectionSchema = z.enum(COLLECTIONS);

/** Loose where passthrough — field → operator → value (string | number | list). */
const whereSchema = z
	.record(
		z.string(),
		z.record(
			z.string(),
			z.union([z.string(), z.number(), z.array(z.union([z.string(), z.number()]))])
		)
	)
	.optional();

/** Manager gate — CMS writes proxy the manager's session cookie. */
function managerCookie(): string | null {
	return getMcpRequestContext()?.cookie ?? null;
}

const MANAGER_REQUIRED = 'cms writes require a manager session cookie';

const NO_BASE = 'commerce base is not configured (set NVOOS_API_BASE or NVOOS_SSR_API_ORIGIN)';

async function readJson(res: Response): Promise<unknown> {
	try {
		return await res.json();
	} catch {
		return null;
	}
}

/** Typed passthrough for backend 401/404/5xx on writes. */
function statusError(method: string, url: string, status: number, payload: unknown) {
	return errorResult(`cms ${method} failed: backend responded ${status}`, {
		error: 'backend_error',
		method,
		url,
		status,
		payload,
	});
}

/** Shared fetch+map for POST/PATCH/DELETE write calls. */
async function writeRequest(
	method: 'POST' | 'PATCH' | 'DELETE',
	path: string,
	body: Record<string, unknown> | null
) {
	const cookie = managerCookie();
	if (!cookie) {
		return errorResult(MANAGER_REQUIRED, { error: 'manager_session_required' });
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
		return errorResult(`cms ${method} failed: ${(error as Error).message}`, {
			error: 'request_failed',
		});
	}
	const payload = await readJson(res);
	if (!res.ok) {
		return statusError(method, url, res.status, payload);
	}
	return text(JSON.stringify(payload, null, 2), payload);
}

export function registerCmsTools(server: McpServer): void {
	server.registerTool(
		'cms_list',
		{
			description:
				'List a collection from the commerce contract (paginated envelope: docs, totals, page). Read-only.',
			inputSchema: z.object({
				collection: collectionSchema,
				page: z.number().int().positive().optional(),
				limit: z.number().int().positive().max(MAX_LIMIT).optional(),
				where: whereSchema,
				sort: z.string().optional(),
			}),
		},
		async (args) => {
			const base = resolveApiBase();
			if (!base) {
				return errorResult(NO_BASE, { error: 'no_commerce_base' });
			}
			const query = buildCollectionQuery({
				page: args.page,
				limit: args.limit,
				where: args.where as WhereClause | undefined,
				sort: args.sort as SortValue | undefined,
			});
			const url = `${joinPath(base, args.collection)}${query ? `?${query}` : ''}`;
			let res: Response;
			try {
				res = await fetch(url, { headers: { accept: 'application/json' } });
			} catch (error) {
				return errorResult(`cms_list failed: ${(error as Error).message}`, {
					error: 'request_failed',
				});
			}
			const payload = await readJson(res);
			const envelope = payload as Record<string, unknown> | null;
			if (!res.ok || !envelope || !Array.isArray(envelope.docs)) {
				return errorResult(`cms_list failed: backend responded ${res.status}`, {
					error: 'backend_error',
					status: res.status,
				});
			}
			const summarized = {
				collection: args.collection,
				docs: envelope.docs,
				totalDocs: envelope.totalDocs,
				limit: envelope.limit,
				totalPages: envelope.totalPages,
				page: envelope.page,
				hasNextPage: envelope.hasNextPage,
				hasPrevPage: envelope.hasPrevPage,
			};
			return text(JSON.stringify(summarized, null, 2), summarized);
		}
	);

	server.registerTool(
		'cms_get',
		{
			description:
				'Fetch one collection document by id (or by slug via a where-equals lookup). A missing document surfaces as a typed error result. Read-only.',
			inputSchema: z.object({
				collection: collectionSchema,
				id: z.number().int().positive().optional(),
				slug: z.string().optional(),
			}),
		},
		async (args) => {
			const base = resolveApiBase();
			if (!base) {
				return errorResult(NO_BASE, { error: 'no_commerce_base' });
			}
			if (args.id === undefined && args.slug === undefined) {
				return errorResult('cms_get requires an id or a slug', { error: 'invalid_arguments' });
			}
			const url =
				args.id !== undefined
					? joinPath(base, `${args.collection}/${args.id}`)
					: `${joinPath(base, args.collection)}?${buildCollectionQuery({
							limit: 1,
							where: { slug: { equals: args.slug ?? '' } } as WhereClause,
						})}`;
			let res: Response;
			try {
				res = await fetch(url, { headers: { accept: 'application/json' } });
			} catch (error) {
				return errorResult(`cms_get failed: ${(error as Error).message}`, {
					error: 'request_failed',
				});
			}
			const payload = await readJson(res);
			const envelope = payload as Record<string, unknown> | null;
			if (!res.ok && res.status !== 404) {
				return errorResult(`cms_get failed: backend responded ${res.status}`, {
					error: 'backend_error',
					status: res.status,
				});
			}
			const doc =
				envelope && Array.isArray(envelope.docs)
					? (envelope.docs as unknown[])[0]
					: envelope;
			if (res.status === 404 || doc === undefined || doc === null) {
				return errorResult(`not found: ${args.collection} ${args.id ?? args.slug}`, {
					error: 'not_found',
					collection: args.collection,
					id: args.id ?? null,
					slug: args.slug ?? null,
				});
			}
			return text(JSON.stringify(doc, null, 2), doc);
		}
	);

	server.registerTool(
		'cms_create',
		{
			description:
				'Create a document in a commerce-contract collection (POST). Manager session required.',
			inputSchema: z.object({
				collection: collectionSchema,
				body: z.record(z.string(), z.unknown()),
			}),
		},
		async (args) => writeRequest('POST', args.collection, args.body)
	);

	server.registerTool(
		'cms_update',
		{
			description:
				'Update one collection document by id (PATCH). Manager session required.',
			inputSchema: z.object({
				collection: collectionSchema,
				id: z.number().int().positive(),
				body: z.record(z.string(), z.unknown()),
			}),
		},
		async (args) => writeRequest('PATCH', `${args.collection}/${args.id}`, args.body)
	);

	server.registerTool(
		'cms_delete',
		{
			description:
				'Delete one collection document by id (DELETE). Manager session required.',
			inputSchema: z.object({
				collection: collectionSchema,
				id: z.number().int().positive(),
			}),
		},
		async (args) => writeRequest('DELETE', `${args.collection}/${args.id}`, null)
	);

	server.registerTool(
		'media_upload',
		{
			description:
				'Upload a media file to the commerce-contract media collection (multipart form, field "file"). Manager session required.',
			inputSchema: z.object({
				name: z.string().min(1),
				mime_type: z.string().min(1),
				data_base64: z.string().min(1),
			}),
		},
		async (args) => {
			const cookie = managerCookie();
			if (!cookie) {
				return errorResult('media uploads require a manager session cookie', {
					error: 'manager_session_required',
				});
			}
			const base = resolveApiBase();
			if (!base) {
				return errorResult(NO_BASE, { error: 'no_commerce_base' });
			}
			const bytes = new Uint8Array(Buffer.from(args.data_base64, 'base64'));
			const form = new FormData();
			form.append('file', new File([bytes], args.name, { type: args.mime_type }));
			const url = joinPath(base, 'media');
			let res: Response;
			try {
				res = await fetch(url, {
					method: 'POST',
					headers: { accept: 'application/json', cookie },
					body: form,
				});
			} catch (error) {
				return errorResult(`media upload failed: ${(error as Error).message}`, {
					error: 'request_failed',
				});
			}
			const payload = await readJson(res);
			if (!res.ok) {
				return statusError('POST', url, res.status, payload);
			}
			return text(JSON.stringify(payload, null, 2), payload);
		}
	);
}
