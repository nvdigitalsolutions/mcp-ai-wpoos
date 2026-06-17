import client, {
	configure, isAvailable, wakeUp, recall, store, update, remove, audit,
	getPreferences, setPreferences, isMemoryRetrievalResult
} from '../dist/nvoos-chat-memory.js';

// Before configure → not available
console.assert(isAvailable() === false, 'should not be available before configure');

// Track fetch calls
let lastCall = null;
function mockFetch(url, opts) {
	lastCall = { url, opts };
	return Promise.resolve({
		ok: true,
		json: () => Promise.resolve({ ok: true, url, method: opts && opts.method }),
	});
}

configure({
	endpoints: {
		wakeUp: 'https://api.example.com/memory/wake-up',
		recall: 'https://api.example.com/memory/recall',
		store: 'https://api.example.com/memory/store',
		itemBase: 'https://api.example.com/memory/items/',
		preferences: 'https://api.example.com/memory/preferences',
		audit: 'https://api.example.com/memory/audit',
	},
	headers: { Authorization: 'Bearer test-token' },
	fetch: mockFetch,
	credentials: 'omit',
});

console.assert(isAvailable() === true, 'should be available after configure');

// wakeUp builds correct querystring & uses Authorization header
await wakeUp({ agentId: 'a1', wing: 'support', room: 'r1' });
console.assert(lastCall.url === 'https://api.example.com/memory/wake-up?agent_id=a1&wing=support&room=r1', 'wakeUp URL wrong: ' + lastCall.url);
console.assert(lastCall.opts.headers.Authorization === 'Bearer test-token', 'extra headers should be merged');
console.assert(lastCall.opts.credentials === 'omit', 'credentials should follow config');
console.assert(lastCall.opts.method === 'GET', 'wakeUp should be GET');

// recall
await recall('test query', { agentId: 'a1', limit: 5 });
console.assert(/recall\?/.test(lastCall.url) && /query=test\+query/.test(lastCall.url), 'recall URL wrong: ' + lastCall.url);

// store sends JSON body
await store({ agentId: 'a1', title: 't', content: 'c' });
console.assert(lastCall.opts.method === 'POST', 'store is POST');
console.assert(lastCall.opts.headers['Content-Type'] === 'application/json', 'store sets JSON content-type');
const parsed = JSON.parse(lastCall.opts.body);
console.assert(parsed.agent_id === 'a1' && parsed.title === 't' && parsed.verbatim === true, 'store body: ' + lastCall.opts.body);

// update encodes contextId
await update('ctx 1/2', { title: 'new' });
console.assert(lastCall.opts.method === 'PUT', 'update is PUT');
console.assert(lastCall.url === 'https://api.example.com/memory/items/ctx%201%2F2', 'update URL: ' + lastCall.url);

// remove with agentId on querystring
await remove('id-99', { agentId: 'a1' });
console.assert(lastCall.opts.method === 'DELETE', 'delete is DELETE');
console.assert(lastCall.url === 'https://api.example.com/memory/items/id-99?agent_id=a1', 'remove URL: ' + lastCall.url);

// audit
await audit({ agentId: 'a1', limit: 10, actionType: 'create' });
console.assert(/audit\?/.test(lastCall.url) && /agent_id=a1/.test(lastCall.url) && /action_type=create/.test(lastCall.url), 'audit URL: ' + lastCall.url);

// preferences
await getPreferences();
console.assert(lastCall.url === 'https://api.example.com/memory/preferences', 'getPreferences URL: ' + lastCall.url);
console.assert(lastCall.opts.method === 'GET', 'getPreferences is GET');

await setPreferences({ enabled: true });
console.assert(lastCall.opts.method === 'POST', 'setPreferences is POST');

// isMemoryRetrievalResult
console.assert(isMemoryRetrievalResult({ contexts: [] }) === true);
console.assert(isMemoryRetrievalResult({ results: [] }) === true);
console.assert(isMemoryRetrievalResult({ memories: [] }) === true);
console.assert(isMemoryRetrievalResult({ other: 1 }) === false);
console.assert(isMemoryRetrievalResult(null) === false);

// HTTP error path
configure({
	endpoints: {
		wakeUp: 'https://api.example.com/memory/wake-up',
		recall: 'https://api.example.com/memory/recall',
		store: 'https://api.example.com/memory/store',
		itemBase: 'https://api.example.com/memory/items/',
		preferences: 'https://api.example.com/memory/preferences',
		audit: 'https://api.example.com/memory/audit',
	},
	fetch: () => Promise.resolve({
		ok: false,
		status: 403,
		json: () => Promise.resolve({ message: 'Forbidden', code: 'rest_forbidden' }),
	}),
});

let caught;
try {
	await wakeUp({ agentId: 'a1' });
} catch (e) {
	caught = e;
}
console.assert(caught && caught.status === 403 && /Forbidden/.test(caught.message), 'should reject with status: ' + (caught && caught.message));

// Default export shape
console.assert(typeof client.configure === 'function');
console.assert(client['delete'] === client.remove);

console.log('All smoke tests passed.');
