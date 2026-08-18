/**
 * Crawl4AI-compatible facade.
 *
 * Mirrors the remote-service contract the WordPress plugin's
 * `run_crawl4ai_job` tool and `WP_MCP_AI_Crawler` WP-Cron poller already
 * speak, so pointing WP_MCP_AI_CRAWL4AI_BASE_URL at
 * http://media-worker:3100/api/crawl4ai makes the media worker a drop-in
 * Crawl4AI service with zero plugin code changes:
 *
 *   POST /api/crawl4ai/crawl       → { task_id } (immediate)
 *   GET  /api/crawl4ai/task/:id    → { status, task_id, results, metadata }
 *
 * Contract notes (mirrors includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php):
 *   - status: "pending" | "processing" | "completed" (polled every interval)
 *   - results: per-URL objects with markdown/text/html, url, title,
 *     status_code — the plugin truncates these itself.
 *   - "failed"/"error" statuses or a non-empty `error` key make the plugin
 *     fail the job — this facade never returns them for pending tasks.
 *
 * Extraction strategies:
 *   - NoExtractionStrategy      → tiered Markdown pipeline (crawl routes)
 *   - JsonCssExtractionStrategy → Cheerio CSS-schema extraction (static HTML)
 *   - LLMExtractionStrategy     → multi-provider structured-JSON extraction
 *                                 (OpenAI, Gemini, Anthropic, DeepSeek, or any
 *                                 OpenAI-compatible CRAWL_LLM_BASE_URL;
 *                                 501 when no provider is configured)
 *
 * Tasks live in an in-memory store with a TTL sweep (same single-process
 * caveat as the in-memory queue fallback). The queue jobs themselves are
 * Redis-backed when REDIS_URL is set; a task whose store entry is gone
 * (worker restart) is silently dropped by the handler.
 */

import { Router } from 'express';
import { getQueue } from '../queue.js';
import { resolvePublicUrl } from '../utils/safe-url.js';
import { crawlUrl, safeFetchHtml, CRAWL_MAX_URLS_BATCH, CRAWL_TIMEOUT_MS } from './crawl.js';
import { extractCssFromHtml } from '../utils/crawl-extract.js';
import { llmExtractJson, resolveLlmProvider } from '../utils/llm-extract.js';

export const crawl4aiRouter = Router();

const VALID_STRATEGIES = [ 'NoExtractionStrategy', 'JsonCssExtractionStrategy', 'LLMExtractionStrategy' ];

/**
 * Task TTL from env, or 30 minutes.
 *
 * @return {number} TTL in ms.
 */
function taskTtlMs() {
	const value = Number( process.env.CRAWL_TASK_TTL_MS );
	return Number.isFinite( value ) && value > 0 ? value : 30 * 60 * 1000;
}

const CRAWL_TASK_TTL_MS = taskTtlMs();

// ── Task store (in-memory, TTL-swept) ───────────────────────

const tasks = new Map();
const handledQueues = new WeakSet();

setInterval( () => sweepTasks(), 5 * 60 * 1000 ).unref();

/**
 * Remove tasks older than CRAWL_TASK_TTL_MS.
 *
 * @param {number} [now] Current time (ms) — injectable for tests.
 */
export function sweepTasks( now = Date.now() ) {
	for ( const [ taskId, task ] of tasks ) {
		if ( now - task.created_at > CRAWL_TASK_TTL_MS ) {
			tasks.delete( taskId );
		}
	}
}

/**
 * Persist a task descriptor.
 *
 * @param {Object} task Task descriptor.
 */
export function storeTask( task ) {
	tasks.set( task.task_id, task );
}

/**
 * Look up a task descriptor.
 *
 * @param {string} taskId Task identifier.
 * @return {Object|undefined} Task descriptor.
 */
export function fetchTask( taskId ) {
	return tasks.get( taskId );
}

/**
 * Remove every task (test helper).
 */
export function clearTasks() {
	tasks.clear();
}

// ── LLM extraction (LLMExtractionStrategy) ──────────────────
//
// Delegated to utils/llm-extract.js: provider autodetection across OpenAI,
// Gemini, Anthropic, DeepSeek, and any OpenAI-compatible base URL.

/**
 * Extract structured JSON from crawled Markdown via the resolved LLM
 * provider (see utils/llm-extract.js for selection rules).
 *
 * @param {string} markdown    Crawled page content.
 * @param {string|null} instruction Optional extraction instruction.
 * @param {string} site        Site slug (per-site credential resolution).
 * @param {Object} [deps]      Injectable dependencies.
 * @return {Promise<Object>} Parsed JSON object.
 */
async function llmExtract( markdown, instruction, site, deps = {} ) {
	return llmExtractJson( { markdown, instruction, site }, deps );
}

// ── Queue handler ───────────────────────────────────────────

/**
 * Ensure the crawl4ai-task handler is registered on a queue (once per queue).
 *
 * @param {Object} queue JobQueue instance.
 */
function ensureTaskHandler( queue ) {
	if ( handledQueues.has( queue ) ) {
		return;
	}
	handledQueues.add( queue );

	queue.process( 'crawl4ai-task', ( job ) => processTask( job ) );
}

/**
 * Process one queued facade task: crawl every URL and store the results.
 *
 * @param {Object} job  Queue job ({ data: { task_id } }).
 * @param {Object} [deps] Injectable ({ crawlUrl, fetchHtml, llmExtract }).
 */
export async function processTask( job, deps = {} ) {
	const { task_id: taskId } = job.data || {};
	const task = fetchTask( taskId );
	if ( ! task ) {
		return; // Swept by TTL or lost on restart — nothing to update.
	}

	const crawler = deps.crawlUrl || crawlUrl;
	const fetcher = deps.fetchHtml || safeFetchHtml;
	const llm = deps.llmExtract || llmExtract;

	task.status = 'processing';

	const results = [];
	for ( const url of task.urls ) {
		try {
			if ( 'JsonCssExtractionStrategy' === task.strategy ) {
				// CSS schemas operate on static HTML only (documented v1 limit).
				const fetched = await fetcher( url, { timeout_ms: CRAWL_TIMEOUT_MS } );
				results.push( {
					url,
					success: true,
					status_code: fetched.status_code,
					extracted_json: extractCssFromHtml( fetched.html, task.css_schema ),
				} );
				continue;
			}

			const crawl = await crawler( url, { render: 'auto' } );
			const entry = {
				url,
				title: crawl.title,
				markdown: crawl.markdown,
				status_code: crawl.status_code,
				success: true,
				rendered: crawl.rendered,
				word_count: crawl.word_count,
			};

			if ( 'LLMExtractionStrategy' === task.strategy ) {
				entry.extracted_json = await llm( crawl.markdown, task.llm_instruction, task.site, deps );
			}

			if ( task.word_count_threshold && crawl.word_count < task.word_count_threshold ) {
				entry.success = false;
				entry.error = 'content_below_word_count_threshold';
			}

			results.push( entry );
		} catch ( err ) {
			results.push( { url, success: false, error: err.message, status_code: err.status || null } );
		}
	}

	task.results = results;
	task.status = 'completed';
	task.metadata.completed_at = new Date().toISOString();
	task.metadata.crawled = results.length;
}

// ── POST /crawl — submit (contract: returns task_id) ────────

/**
 * Validate and enqueue a Crawl4AI-style crawl submission.
 *
 * @param {Object} payload Request body.
 * @param {string} [site]  Site slug (multi-tenant namespace).
	 * @param {Object} [deps]  Injectable ({ getQueue, resolveLlmProvider }).
	 * @return {Promise<{statusCode: number, body: Object}>} HTTP result.
	 */
export async function submitCrawl( payload, site = 'default', deps = {} ) {
	const queueFactory = deps.getQueue || getQueue;

	const urls = Array.isArray( payload.urls ) ? payload.urls : null;
	if ( ! urls || 0 === urls.length ) {
		return { statusCode: 400, body: { error: 'urls must be a non-empty array' } };
	}
	if ( urls.length > CRAWL_MAX_URLS_BATCH ) {
		return { statusCode: 400, body: { error: `Batch size exceeds CRAWL_MAX_URLS_BATCH (${ CRAWL_MAX_URLS_BATCH })` } };
	}

	const strategy = 'string' === typeof payload.extraction_strategy && payload.extraction_strategy
		? payload.extraction_strategy
		: 'NoExtractionStrategy';
	if ( ! VALID_STRATEGIES.includes( strategy ) ) {
		return { statusCode: 400, body: { error: `Unsupported extraction_strategy: ${ strategy }` } };
	}

	let cssSchema = null;
	if ( 'JsonCssExtractionStrategy' === strategy ) {
		cssSchema = payload.css_schema || payload.schema
			|| ( payload.options && ( payload.options.css_schema || payload.options.schema ) );
		if ( ! cssSchema || ! Array.isArray( cssSchema.fields ) || 0 === cssSchema.fields.length ) {
			return { statusCode: 400, body: { error: 'JsonCssExtractionStrategy requires a css_schema with a non-empty fields array.' } };
		}
	}

	if ( 'LLMExtractionStrategy' === strategy ) {
		const providerResolver = deps.resolveLlmProvider || resolveLlmProvider;
		try {
			if ( ! providerResolver( site, deps ) ) {
				return { statusCode: 501, body: { error: 'LLMExtractionStrategy requires a configured LLM provider (OPENAI_API_KEY, GEMINI_API_KEY, ANTHROPIC_API_KEY, DEEPSEEK_API_KEY, or CRAWL_LLM_BASE_URL).' } };
			}
		} catch ( err ) {
			return { statusCode: err.status && err.status >= 400 && err.status < 600 ? err.status : 400, body: { error: err.message } };
		}
	}

	const normalized = [];
	for ( const rawUrl of urls ) {
		if ( 'string' !== typeof rawUrl || '' === rawUrl ) {
			return { statusCode: 400, body: { error: 'Every url must be a non-empty string.' } };
		}
		try {
			normalized.push( ( await resolvePublicUrl( rawUrl ) ).toString() );
		} catch ( err ) {
			return { statusCode: 400, body: { error: `Invalid URL: ${ rawUrl }` } };
		}
	}

	const rawThreshold = Number( payload.word_count_threshold );
	const wordThreshold = Number.isFinite( rawThreshold ) && rawThreshold > 0 ? rawThreshold : null;
	const llmInstruction = 'string' === typeof payload.instruction && payload.instruction
		? payload.instruction
		: ( 'string' === typeof payload.user_prompt && payload.user_prompt ? payload.user_prompt : null );

	const task = {
		task_id: `mw-${ Date.now().toString( 36 ) }-${ Math.random().toString( 36 ).slice( 2, 9 ) }`,
		site,
		status: 'pending',
		urls: normalized,
		strategy,
		word_count_threshold: wordThreshold,
		llm_instruction: llmInstruction,
		css_schema: cssSchema,
		results: [],
		metadata: { submitted_at: new Date().toISOString() },
		created_at: Date.now(),
	};
	storeTask( task );

	const queue = queueFactory( 'crawl4ai', site );
	ensureTaskHandler( queue );
	await queue.add( 'crawl4ai-task', { task_id: task.task_id }, { attempts: 2 } );

	return { statusCode: 200, body: { task_id: task.task_id } };
}

// ── GET /task/:task_id — poll status ────────────────────────

/**
 * Read a task's status in the Crawl4AI contract shape.
 *
 * @param {string} taskId Task identifier.
 * @return {{statusCode: number, body: Object}} HTTP result.
 */
export function getTaskStatus( taskId ) {
	const task = fetchTask( taskId );
	if ( ! task ) {
		return { statusCode: 404, body: { error: 'Task not found' } };
	}
	return {
		statusCode: 200,
		body: {
			status: task.status,
			task_id: task.task_id,
			results: task.results || [],
			metadata: task.metadata || {},
		},
	};
}

// ── Route wiring ────────────────────────────────────────────

crawl4aiRouter.post( '/crawl', async ( req, res ) => {
	try {
		const { statusCode, body } = await submitCrawl( req.body || {}, req.site || 'default' );
		res.status( statusCode ).json( body );
	} catch ( err ) {
		res.status( err.status && err.status >= 400 && err.status < 600 ? err.status : 502 )
			.json( { error: err.message } );
	}
} );

crawl4aiRouter.get( '/task/:task_id', ( req, res ) => {
	const { statusCode, body } = getTaskStatus( req.params.task_id );
	res.status( statusCode ).json( body );
} );
