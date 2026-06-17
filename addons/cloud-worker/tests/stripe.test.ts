/**
 * Stripe-webhook signature verification tests.
 *
 * Builds a synthetic Stripe-Signature header with a known secret and asserts
 * verifyWebhook accepts it; flips one byte and asserts rejection.
 */

import { describe, expect, it } from 'vitest';
import { verifyWebhook } from '../src/stripe';

const SECRET = 'whsec_test_secret';

async function sign(payload: string, secret: string, ts: number): Promise<string> {
	const enc = new TextEncoder();
	const key = await crypto.subtle.importKey(
		'raw',
		enc.encode(secret),
		{ name: 'HMAC', hash: 'SHA-256' },
		false,
		['sign']
	);
	const buf = await crypto.subtle.sign('HMAC', key, enc.encode(`${ts}.${payload}`));
	const bytes = new Uint8Array(buf);
	let hex = '';
	for (let i = 0; i < bytes.length; i += 1) {
		hex += bytes[i]!.toString(16).padStart(2, '0');
	}
	return hex;
}

describe('stripe webhook verification', () => {
	const payload = JSON.stringify({
		id: 'evt_test_123',
		type: 'checkout.session.completed',
		data: { object: { id: 'cs_test_abc' } },
	});

	it('accepts a properly signed event', async () => {
		const ts = Math.floor(Date.now() / 1000);
		const sig = await sign(payload, SECRET, ts);
		const header = `t=${ts},v1=${sig}`;
		const event = await verifyWebhook(payload, header, SECRET);
		expect(event.id).toBe('evt_test_123');
		expect(event.type).toBe('checkout.session.completed');
	});

	it('rejects a signature mismatch', async () => {
		const ts = Math.floor(Date.now() / 1000);
		const sig = await sign(payload, SECRET, ts);
		const tampered = sig.replace(/.$/, sig.endsWith('0') ? '1' : '0');
		const header = `t=${ts},v1=${tampered}`;
		await expect(verifyWebhook(payload, header, SECRET)).rejects.toThrow(/signature_mismatch/);
	});

	it('rejects timestamps outside the 5-minute tolerance', async () => {
		const ts = Math.floor(Date.now() / 1000) - 10 * 60;
		const sig = await sign(payload, SECRET, ts);
		const header = `t=${ts},v1=${sig}`;
		await expect(verifyWebhook(payload, header, SECRET)).rejects.toThrow(
			/timestamp_outside_tolerance/
		);
	});

	it('rejects a payload with a tampered body', async () => {
		const ts = Math.floor(Date.now() / 1000);
		const sig = await sign(payload, SECRET, ts);
		const header = `t=${ts},v1=${sig}`;
		const tampered = payload.replace('evt_test_123', 'evt_attacker_xyz');
		await expect(verifyWebhook(tampered, header, SECRET)).rejects.toThrow(/signature_mismatch/);
	});

	it('rejects a missing Stripe-Signature header', async () => {
		await expect(verifyWebhook(payload, null, SECRET)).rejects.toThrow(/missing_signature/);
	});
});
