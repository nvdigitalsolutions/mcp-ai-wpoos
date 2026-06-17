import pkg, { getTools, getTool, configure, executeTool, CLIENT_TOOLS } from '../dist/nvoos-client-tools.js';

// Smoke tests
const tools = getTools();
console.assert(typeof tools === 'object', 'getTools should return object');
console.assert('client_summarize' in tools, 'client_summarize should be present');
console.assert('client_sentiment' in tools, 'client_sentiment should be present');
console.assert(Object.keys(tools).length >= 7, 'should have at least 7 tools');

const t = getTool('client_summarize');
console.assert(t && t.name === 'client_summarize', 'getTool should resolve client_summarize');
console.assert(getTool('nope') === null, 'unknown tool returns null');

// Should fail without configure
try {
  await executeTool('client_summarize', { text: 'x' });
  console.error('FAIL: should have thrown');
} catch (e) {
  console.assert(/pipeline factory/.test(e.message), 'expected pipeline factory error');
}

// Configure with mock pipeline
configure({
  pipeline: async () => async (input) => [{ summary_text: 'mocked: ' + input }],
});
const out = await executeTool('client_summarize', { text: 'hello' });
console.assert(out === 'mocked: hello', 'mocked summarize should return correctly: ' + out);

console.assert(pkg.CLIENT_TOOLS === CLIENT_TOOLS, 'default export references same registry');

console.log('All smoke tests passed.');
