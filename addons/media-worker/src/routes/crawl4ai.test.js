/**
 * Tests for the Crawl4AI-compatible facade.
 *
 * No network or browser access is required: URL validation uses IP
 * literals, the queue is stubbed, and extraction tiers are injected.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	submitCrawl,
	getTaskStatus,
	processTask,
	storeTask,
	fetchTask,
	clearTasks,
	sweepTasks,
} from './crawl4ai.js';

const PUBLIC_URL = 'http://93.184.216.34/page';
const PRIVATE_URL = 'http://127.0.0.1/secret';

/** Stub queue capturing additions. */
function fakeQueue() {
	const added = [];
	return {
		added,
		add: async ( type, data, options ) => {
			added.push( { type, data, options } );
			return { id: 'job-1' };
		},
		process: () => {},
	};
}

/** Fake extraction tiers. */
function fakeDeps( overrides = {} ) {
	return {
		crawlUrl: async ( url ) => ( {
			title: 'T',
			markdown: 'Hello from ' + url,
			status_code: 200,
			rendered: false,
			word_count: 500,
		} ),
		fetchHtml: async ( url ) => ( { html: '<html><h1>X</h1></html>', final_url: url, status_code: 200 } ),
		llmExtract: async () => ( { answer: 42 } ),
		...overrides,
	};
}

/** Submit helper with stubbed queue/provider resolution. */
function submit( payload, overrides = {} ) {
	const queue = fakeQueue();
	const deps = {
		getQueue: () => queue,
		resolveLlmProvider: () => null,
		...overrides,
	};
	return { queue, deps, run: () => submitCrawl( payload, 'default', deps ) };
}

test( 'submitCrawl rejects missing or empty urls', async () => {
	assert.equal( ( await submitCrawl( {}, 'default', { getQueue: () => fakeQueue() } ) ).statusCode, 400 );
	assert.equal( ( await submitCrawl( { urls: [] }, 'default', { getQueue: () => fakeQueue() } ) ).statusCode, 400 );
} );

test( 'submitCrawl rejects batches above the cap', async () => {
	const urls = Array.from( { length: 11 }, ( _, i ) => `http://93.184.216.3${ i % 10 }/p` );
	const { run } = submit( { urls } );
	const { statusCode, body } = await run();
	assert.equal( statusCode, 400 );
	assert.match( body.error, /CRAWL_MAX_URLS_BATCH/ );
} );

test( 'submitCrawl rejects private URLs with a 400', async () => {
	const { run } = submit( { urls: [ PRIVATE_URL ] } );
	const { statusCode } = await run();
	assert.equal( statusCode, 400 );
} );

test( 'submitCrawl rejects unknown extraction strategies', async () => {
	const { run } = submit( { urls: [ PUBLIC_URL ], extraction_strategy: 'MagicStrategy' } );
	assert.equal( ( await run() ).statusCode, 400 );
} );

test( 'submitCrawl requires a css_schema for JsonCssExtractionStrategy', async () => {
	const { run } = submit( { urls: [ PUBLIC_URL ], extraction_strategy: 'JsonCssExtractionStrategy' } );
	assert.equal( ( await run() ).statusCode, 400 );
} );

test( 'submitCrawl returns 501 for LLM strategy when no provider is configured', async () => {
	const { run } = submit( { urls: [ PUBLIC_URL ], extraction_strategy: 'LLMExtractionStrategy' } );
	const { statusCode, body } = await run();
	assert.equal( statusCode, 501 );
	assert.match( body.error, /configured LLM provider/ );
} );

test( 'submitCrawl accepts LLM strategy when any provider is available', async () => {
	clearTasks();
	const { run } = submit( { urls: [ PUBLIC_URL ], extraction_strategy: 'LLMExtractionStrategy' }, {
		resolveLlmProvider: () => ( { provider: 'gemini', model: 'gemini-2.5-flash', apiKey: 'key-123' } ),
	} );
	const { statusCode, body } = await run();
	assert.equal( statusCode, 200 );
	assert.equal( fetchTask( body.task_id ).strategy, 'LLMExtractionStrategy' );
	clearTasks();
} );

test( 'submitCrawl enqueues a job and returns a task_id', async () => {
	clearTasks();
	const { queue, run } = submit( { urls: [ PUBLIC_URL ] } );
	const { statusCode, body } = await run();

	assert.equal( statusCode, 200 );
	assert.match( body.task_id, /^mw-/ );
	assert.equal( queue.added.length, 1 );
	assert.equal( queue.added[ 0 ].type, 'crawl4ai-task' );
	assert.equal( queue.added[ 0 ].data.task_id, body.task_id );

	const task = fetchTask( body.task_id );
	assert.equal( task.status, 'pending' );
	assert.deepEqual( task.urls, [ PUBLIC_URL ] );
	assert.equal( task.strategy, 'NoExtractionStrategy' );

	const status = getTaskStatus( body.task_id );
	assert.equal( status.statusCode, 200 );
	assert.equal( status.body.status, 'pending' );
	assert.deepEqual( status.body.results, [] );
	clearTasks();
} );

test( 'getTaskStatus 404s for unknown tasks', () => {
	const status = getTaskStatus( 'mw-unknown' );
	assert.equal( status.statusCode, 404 );
} );

test( 'processTask completes a task with per-URL results', async () => {
	clearTasks();
	storeTask( {
		task_id: 'mw-test-1',
		site: 'default',
		status: 'pending',
		urls: [ PUBLIC_URL ],
		strategy: 'NoExtractionStrategy',
		word_count_threshold: null,
		llm_instruction: null,
		css_schema: null,
		results: [],
		metadata: {},
		created_at: Date.now(),
	} );

	await processTask( { data: { task_id: 'mw-test-1' } }, fakeDeps() );

	const task = fetchTask( 'mw-test-1' );
	assert.equal( task.status, 'completed' );
	assert.equal( task.results.length, 1 );
	assert.equal( task.results[ 0 ].success, true );
	assert.ok( task.results[ 0 ].markdown.includes( 'Hello from' ) );
	clearTasks();
} );

test( 'processTask flags content below the word count threshold', async () => {
	clearTasks();
	storeTask( {
		task_id: 'mw-test-2',
		site: 'default',
		status: 'pending',
		urls: [ PUBLIC_URL ],
		strategy: 'NoExtractionStrategy',
		word_count_threshold: 1000,
		llm_instruction: null,
		css_schema: null,
		results: [],
		metadata: {},
		created_at: Date.now(),
	} );

	await processTask( { data: { task_id: 'mw-test-2' } }, fakeDeps() );

	const task = fetchTask( 'mw-test-2' );
	assert.equal( task.results[ 0 ].success, false );
	assert.equal( task.results[ 0 ].error, 'content_below_word_count_threshold' );
	clearTasks();
} );

test( 'processTask keeps going after per-URL failures', async () => {
	clearTasks();
	storeTask( {
		task_id: 'mw-test-3',
		site: 'default',
		status: 'pending',
		urls: [ PRIVATE_URL, PUBLIC_URL ],
		strategy: 'NoExtractionStrategy',
		word_count_threshold: null,
		llm_instruction: null,
		css_schema: null,
		results: [],
		metadata: {},
		created_at: Date.now(),
	} );

	await processTask( { data: { task_id: 'mw-test-3' } }, fakeDeps( {
		crawlUrl: async ( url ) => {
			if ( url === PUBLIC_URL ) {
				return { title: 'T', markdown: 'ok', status_code: 200, rendered: false, word_count: 10 };
			}
			throw Object.assign( new Error( 'nope' ), { status: 400 } );
		},
	} ) );

	const task = fetchTask( 'mw-test-3' );
	assert.equal( task.status, 'completed' );
	assert.equal( task.results.length, 2 );
	assert.equal( task.results[ 0 ].success, false );
	assert.equal( task.results[ 0 ].error, 'nope' );
	assert.equal( task.results[ 1 ].success, true );
	clearTasks();
} );

test( 'processTask runs the CSS strategy against static HTML', async () => {
	clearTasks();
	storeTask( {
		task_id: 'mw-test-4',
		site: 'default',
		status: 'pending',
		urls: [ PUBLIC_URL ],
		strategy: 'JsonCssExtractionStrategy',
		word_count_threshold: null,
		llm_instruction: null,
		css_schema: { baseSelector: 'main', fields: [ { name: 'title', selector: 'h1' } ] },
		results: [],
		metadata: {},
		created_at: Date.now(),
	} );

	await processTask( { data: { task_id: 'mw-test-4' } }, fakeDeps( {
		fetchHtml: async () => ( { html: '<main><h1>Widget</h1></main>', final_url: PUBLIC_URL, status_code: 200 } ),
	} ) );

	const task = fetchTask( 'mw-test-4' );
	assert.equal( task.results[ 0 ].success, true );
	assert.deepEqual( task.results[ 0 ].extracted_json, { title: 'Widget' } );
	clearTasks();
} );

test( 'processTask attaches LLM extraction results', async () => {
	clearTasks();
	storeTask( {
		task_id: 'mw-test-5',
		site: 'default',
		status: 'pending',
		urls: [ PUBLIC_URL ],
		strategy: 'LLMExtractionStrategy',
		word_count_threshold: null,
		llm_instruction: 'Find the price',
		css_schema: null,
		results: [],
		metadata: {},
		created_at: Date.now(),
	} );

	await processTask( { data: { task_id: 'mw-test-5' } }, fakeDeps() );

	const task = fetchTask( 'mw-test-5' );
	assert.deepEqual( task.results[ 0 ].extracted_json, { answer: 42 } );
	clearTasks();
} );

test( 'processTask ignores jobs whose task no longer exists', async () => {
	clearTasks();
	// Must not throw — the task may have been swept by TTL.
	await processTask( { data: { task_id: 'mw-gone' } }, fakeDeps() );
} );

test( 'sweepTasks removes expired tasks and keeps fresh ones', () => {
	clearTasks();
	storeTask( { task_id: 'old', created_at: 1, results: [], metadata: {} } );
	storeTask( { task_id: 'new', created_at: Date.now(), results: [], metadata: {} } );

	sweepTasks( Date.now() );
	assert.equal( fetchTask( 'old' ), undefined );
	assert.ok( fetchTask( 'new' ) );
	clearTasks();
} );
