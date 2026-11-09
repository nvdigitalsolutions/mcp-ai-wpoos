// ---------------------------------------------------------------------------
// forms.test.ts — the guards behind /forms/submit (Proposal 138).
//
// These are the layers that decide whether a real enquiry reaches the inbox
// and whether a bot's does not, so each one is pinned: a guard that silently
// widens is how a contact form turns into a spam relay.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import {
	MAX_BYTES,
	RATE_LIMIT,
	buildText,
	createThrottle,
	idempotencyKey,
	isBot,
	readFields,
	replyToOf,
	visibleRows,
} from '../../src/lib/forms';

function post(body: string, contentType: string, headers: Record<string, string> = {}): Request {
	return new Request('https://store.example/forms/submit', {
		method: 'POST',
		headers: { 'content-type': contentType, ...headers },
		body,
	});
}

describe('readFields', () => {
	it('reads a JSON object as string pairs', async () => {
		const request = post(JSON.stringify({ name: 'Ada', email: 'ada@example.com', age: 36 }), 'application/json');
		await expect(readFields(request)).resolves.toEqual({
			name: 'Ada',
			email: 'ada@example.com',
			age: '36',
		});
	});

	it('reads a urlencoded body', async () => {
		const request = post('name=Ada&email=ada%40example.com', 'application/x-www-form-urlencoded');
		await expect(readFields(request)).resolves.toEqual({
			name: 'Ada',
			email: 'ada@example.com',
		});
	});

	it('rejects a body over the cap, even without content-length', async () => {
		const request = post('x'.repeat(MAX_BYTES + 1), 'application/x-www-form-urlencoded');
		await expect(readFields(request)).resolves.toBeUndefined();
	});

	it('rejects malformed JSON rather than throwing', async () => {
		const request = post('{not json', 'application/json');
		await expect(readFields(request)).resolves.toBeUndefined();
	});

	it('rejects a JSON non-object', async () => {
		const request = post('"just a string"', 'application/json');
		await expect(readFields(request)).resolves.toBeUndefined();
	});
});

describe('isBot', () => {
	it('flags a filled honeypot', () => {
		expect(isBot({ honeypot: 'http://spam.example' })).toBe(true);
	});

	it('ignores a whitespace-only honeypot', () => {
		expect(isBot({ honeypot: '   ', timestamp: '0' })).toBe(false);
	});

	it('flags a submit that is too fast to be human', () => {
		const now = 1_000_000;
		expect(isBot({ timestamp: String(now - 500), name: 'Ada' }, now)).toBe(true);
	});

	it('passes a submit past the time trap', () => {
		const now = 1_000_000;
		expect(isBot({ timestamp: String(now - 5000), name: 'Ada' }, now)).toBe(false);
	});

	it('does not flag a missing timestamp — a lost hidden field is not a bot', () => {
		expect(isBot({ name: 'Ada' })).toBe(false);
	});
});

describe('createThrottle', () => {
	it('allows up to the limit then blocks', () => {
		const throttled = createThrottle(RATE_LIMIT, 1000);
		for (let i = 0; i < RATE_LIMIT; i += 1) {
			expect(throttled('1.2.3.4', 0)).toBe(false);
		}
		expect(throttled('1.2.3.4', 0)).toBe(true);
	});

	it('keeps buckets per address', () => {
		const throttled = createThrottle(1, 1000);
		expect(throttled('1.1.1.1', 0)).toBe(false);
		expect(throttled('2.2.2.2', 0)).toBe(false);
		expect(throttled('1.1.1.1', 0)).toBe(true);
	});

	it('forgets hits once the window has passed', () => {
		const throttled = createThrottle(1, 1000);
		expect(throttled('1.1.1.1', 0)).toBe(false);
		expect(throttled('1.1.1.1', 0)).toBe(true);
		expect(throttled('1.1.1.1', 5000)).toBe(false);
	});
});

describe('visibleRows', () => {
	it('drops the fields the form owns', () => {
		const rows = visibleRows({
			name: 'Ada',
			email: 'ada@example.com',
			honeypot: '',
			timestamp: '123',
			_redirect: '/',
			form: 'contact',
			post_id: '7',
		});
		expect(rows).toEqual(['name: Ada', 'email: ada@example.com']);
	});

	it('truncates a very long value', () => {
		const [row] = visibleRows({ message: 'x'.repeat(5000) });
		expect(row.length).toBe('message: '.length + 2000);
	});
});

describe('idempotencyKey', () => {
	it('is stable for the same rows and 32 hex characters', () => {
		const rows = ['name: Ada', 'message: hello'];
		const key = idempotencyKey(rows);
		expect(key).toBe(idempotencyKey(['name: Ada', 'message: hello']));
		expect(key).toMatch(/^[0-9a-f]{32}$/);
	});

	it('changes when the content changes', () => {
		expect(idempotencyKey(['message: hello'])).not.toBe(idempotencyKey(['message: goodbye']));
	});
});

describe('buildText', () => {
	it('keeps every row and marks the source', () => {
		const text = buildText(['name: Ada', 'message: hi']);
		expect(text).toContain('name: Ada');
		expect(text).toContain('message: hi');
		expect(text).toContain('Sent from a form on the website.');
	});
});

describe('replyToOf', () => {
	it('picks the email field', () => {
		expect(replyToOf({ email: 'ada@example.com' })).toBe('ada@example.com');
	});

	it('accepts the hyphenated variant', () => {
		expect(replyToOf({ 'e-mail': 'ada@example.com' })).toBe('ada@example.com');
	});

	it('rejects a value that is not an address', () => {
		expect(replyToOf({ email: 'not-an-address' })).toBeUndefined();
	});

	it('returns undefined when the visitor left no address', () => {
		expect(replyToOf({ name: 'Ada' })).toBeUndefined();
	});
});
