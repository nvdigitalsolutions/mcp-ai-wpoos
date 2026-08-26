/**
 * Unit tests for the shared SSE utilities powering the Chat Tester.
 *
 * Runs with the repo-root Jest config (tests/js/**) — no addon-level
 * tooling required:
 *
 *     npm test -- tests/js/content-graph-ai-sse.test.js
 */

const Sse = require('../../assets/js/content-graph-ai-sse.js');

describe('NvoosContentGraphAiSse.parseSseBuffer', () => {
	it('parses a single complete event', () => {
		const buffer = 'event: message\ndata: {"type":"text","content":"Hi"}\n\n';
		const { frames, rest } = Sse.parseSseBuffer(buffer);

		expect(frames).toHaveLength(1);
		expect(frames[0]).toEqual({ type: 'text', content: 'Hi' });
		expect(rest).toBe('');
	});

	it('parses multiple events and keeps incomplete remainder', () => {
		const buffer =
			'event: message\ndata: {"a":1}\n\n' +
			'event: message\ndata: {"b":';
		const { frames, rest } = Sse.parseSseBuffer(buffer);

		expect(frames).toHaveLength(1);
		expect(frames[0]).toEqual({ a: 1 });
		expect(rest).toContain('{"b":');
	});

	it('joins multi-line data fields into one payload', () => {
		const buffer = 'event: message\ndata: {"a":\ndata: 1}\n\n';
		const { frames } = Sse.parseSseBuffer(buffer);

		expect(frames).toHaveLength(1);
		expect(frames[0]).toEqual({ a: 1 });
	});

	it('ignores [DONE] and comment/retry lines', () => {
		const buffer = 'retry: 3000\n\ndata: [DONE]\n\n: ping\n\n';
		const { frames, rest } = Sse.parseSseBuffer(buffer);

		expect(frames).toHaveLength(0);
		expect(rest).toBe('');
	});

	it('forces type=error for error events without a type', () => {
		const buffer = 'event: error\ndata: {"code":"rate_limited","message":"slow down"}\n\n';
		const { frames } = Sse.parseSseBuffer(buffer);

		expect(frames[0].type).toBe('error');
		expect(frames[0].code).toBe('rate_limited');
	});

	it('falls back to message_delta for non-JSON payloads', () => {
		const buffer = 'event: message\ndata: plain text token\n\n';
		const { frames } = Sse.parseSseBuffer(buffer);

		expect(frames).toHaveLength(1);
		expect(frames[0]).toEqual({ type: 'message_delta', delta: 'plain text token' });
	});
});

describe('NvoosContentGraphAiSse frame extractors', () => {
	it('extracts deltas from the choices shape', () => {
		const frame = { choices: [{ delta: { content: 'The' } }] };
		expect(Sse.extractDelta(frame)).toBe('The');
	});

	it('extracts flat delta/text/content fields', () => {
		expect(Sse.extractDelta({ delta: 'a' })).toBe('a');
		expect(Sse.extractDelta({ text: 'b' })).toBe('b');
		expect(Sse.extractDelta({ content: 'c' })).toBe('c');
		expect(Sse.extractDelta(null)).toBe('');
	});

	it('extracts the authoritative final content', () => {
		const frame = {
			assistant_id: 0,
			data: { choices: [{ message: { role: 'assistant', content: 'Full answer' } }] },
			tool_results: [],
			cost: null,
		};
		expect(Sse.extractFinalContent(frame)).toBe('Full answer');
		expect(Sse.extractFinalContent({ data: {} })).toBeNull();
	});
});

describe('NvoosContentGraphAiSse.summarizeCost', () => {
	it('maps the server cost object to display fields', () => {
		const summary = Sse.summarizeCost({
			cost_usd: 0.0042,
			is_estimated: true,
			prompt_tokens: 120,
			completion_tokens: 80,
			agentic_iterations_count: 2,
			model: 'gpt-4o',
			provider: 'openai',
		});

		expect(summary.usd).toBeCloseTo(0.0042);
		expect(summary.estimated).toBe(true);
		expect(summary.promptTokens).toBe(120);
		expect(summary.completionTokens).toBe(80);
		expect(summary.iterations).toBe(2);
		expect(summary.model).toBe('gpt-4o');
		expect(summary.provider).toBe('openai');
	});

	it('returns null for unusable cost payloads', () => {
		expect(Sse.summarizeCost(null)).toBeNull();
		expect(Sse.summarizeCost('nope')).toBeNull();
	});
});

describe('NvoosContentGraphAiSse.renderMarkdownLite', () => {
	it('escapes raw HTML at the source', () => {
		const html = Sse.renderMarkdownLite('<script>alert(1)</script>');
		expect(html).not.toContain('<script>');
		expect(html).toContain('&lt;script&gt;');
	});

	it('renders fenced code blocks', () => {
		const html = Sse.renderMarkdownLite('```php\n<?php echo 1;\n```');
		expect(html).toContain('<pre><code class="language-php">');
		expect(html).toContain('&lt;?php');
	});

	it('renders inline code, bold, and italics', () => {
		const html = Sse.renderMarkdownLite('Use `esc_html()` for **output** and *never* trust input.');
		expect(html).toContain('<code>esc_html()</code>');
		expect(html).toContain('<strong>output</strong>');
		expect(html).toContain('<em>never</em>');
	});

	it('renders safe links and strips dangerous schemes', () => {
		const html = Sse.renderMarkdownLite(
			'[docs](https://example.com/x) and [bad](javascript:alert(1))'
		);
		expect(html).toContain('<a href="https://example.com/x"');
		expect(html).not.toContain('javascript:');
	});

	it('renders lists and blockquotes', () => {
		const html = Sse.renderMarkdownLite(
			'- one\n- two\n\n1. first\n2. second\n\n> quoted line'
		);
		expect(html).toContain('<ul>');
		expect(html).toContain('<li>one</li>');
		expect(html).toContain('<ol>');
		expect(html).toContain('<blockquote>quoted line</blockquote>');
	});

	it('renders headings scaled for chat bubbles', () => {
		const html = Sse.renderMarkdownLite('# Title\n## Sub');
		expect(html).toContain('<h3>Title</h3>');
		expect(html).toContain('<h4>Sub</h4>');
	});

	it('handles empty input', () => {
		expect(Sse.renderMarkdownLite('')).toBe('');
		expect(Sse.renderMarkdownLite(null)).toBe('');
	});
});
