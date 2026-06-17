import svc, { configure, CronStatusService } from '../dist/nvoos-cron-status.js';

console.assert(svc === CronStatusService, 'default export === named export');
console.assert(typeof configure === 'function');
console.assert(typeof svc.startMonitoring === 'function');
console.assert(typeof svc.stopMonitoring === 'function');
console.assert(typeof svc.fetchStatusREST === 'function');
console.assert(typeof svc.emitJobUpdates === 'function');

// Default config values
console.assert(svc.fallbackPollingInterval === 30000);
console.assert(svc.maxPollingInterval === 300000);
console.assert(svc.backoffMultiplier === 1.5);

// fetchStatusREST builds correct URL with guestToken priority
let lastFetchCall = null;
globalThis.fetch = (url, opts) => {
	lastFetchCall = { url, opts };
	return Promise.resolve({
		ok: true,
		json: () => Promise.resolve({ jobs: [{ job_id: 'j1', status: 'running' }] }),
	});
};

const data = await svc.fetchStatusREST(
	'/api/cron-status',
	'nonce-abc',
	5,
	'asst-1',
	'guest-token-xyz'
);

console.assert(data && Array.isArray(data.jobs), 'should return parsed JSON');
console.assert(/limit=5/.test(lastFetchCall.url), 'limit in URL: ' + lastFetchCall.url);
console.assert(/assistant_id=asst-1/.test(lastFetchCall.url), 'assistant_id in URL');
// Guest token priority — should add guest_token, NOT _wpnonce
console.assert(/guest_token=guest-token-xyz/.test(lastFetchCall.url), 'guest_token in URL');
console.assert(!/_wpnonce/.test(lastFetchCall.url), 'should not include _wpnonce when guest token present');
console.assert(lastFetchCall.opts.headers['X-WP-MCP-AI-Guest'] === 'guest-token-xyz', 'guest header set');

// Without guest token, falls back to nonce
await svc.fetchStatusREST('/api/cron-status', 'nonce-abc', 10);
console.assert(/_wpnonce=nonce-abc/.test(lastFetchCall.url), 'nonce on querystring');
console.assert(lastFetchCall.opts.headers['X-WP-Nonce'] === 'nonce-abc', 'nonce header');

// emitJobUpdates is a no-op when jobBus not configured
let busCalls = 0;
svc.emitJobUpdates({ jobs: [{ job_id: 'j1' }, { job_id: 'j2' }] });
console.assert(busCalls === 0, 'no bus configured → no calls');

// Configure jobBus + verify dispatch
configure({
	jobBus: { handleJobUpdate: (id, payload) => { busCalls++; } },
});
svc.emitJobUpdates({ jobs: [{ job_id: 'j1' }, { job_id: 'j2' }, {}, null] });
console.assert(busCalls === 2, 'expected 2 bus calls, got ' + busCalls);

// configure jobClickableClass is wired
configure({ jobClickableClass: 'my-clickable' });
// (We can't easily test attachClickHandlers without a DOM. Just verify configure ran.)

// startMonitoring without sseAdapter and without DOM falls back to REST polling
configure({ sseAdapter: null });
let pollCalls = 0;
const origFetch = globalThis.fetch;
globalThis.fetch = (url, opts) => {
	pollCalls++;
	return Promise.resolve({ ok: true, json: () => Promise.resolve({ jobs: [] }) });
};
svc.startMonitoring('container-1', '/api/cron-status', 'n1', () => {});
// First REST call is synchronous-ish — give the microtask a tick.
await new Promise(r => setTimeout(r, 0));
console.assert(pollCalls >= 1, 'startMonitoring should trigger at least one REST call (got ' + pollCalls + ')');
svc.stopMonitoring('container-1');

globalThis.fetch = origFetch;
console.log('All smoke tests passed.');
