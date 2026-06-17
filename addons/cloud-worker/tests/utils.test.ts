/**
 * Unit tests for the dependency-free helpers in src/utils.ts and src/billing.ts.
 *
 * These don't need Miniflare; they only exercise pure functions + the Web
 * Crypto API which is available in the Workers test pool.
 */

import { describe, expect, it } from 'vitest';
import {
	applyMarkup,
	generateConnectToken,
	microToUsd,
	normalizeSiteUrl,
	sha256Hex,
	timingSafeEqual,
	usdToMicro,
} from '../src/utils';
import { computeBilling, billingHeaders } from '../src/billing';
import type { Env } from '../src/types';

const FAKE_ENV: Pick<Env, 'MARKUP_RATE'> = { MARKUP_RATE: '0.07' };

describe('utils', () => {
	it('round-trips USD ⇄ micro-USD without drift', () => {
		expect(usdToMicro(0.0123)).toBe(12_300);
		expect(microToUsd(12_300)).toBeCloseTo(0.0123, 6);
		expect(usdToMicro(1)).toBe(1_000_000);
		expect(microToUsd(1_000_000)).toBe(1);
	});

	it('rejects negative amounts on usdToMicro', () => {
		expect(usdToMicro(-1)).toBe(0);
		expect(usdToMicro(NaN)).toBe(0);
	});

	it('applyMarkup matches plugin math (7%)', () => {
		const result = applyMarkup(0.001, 0.07);
		expect(result.wholesale_micro_usd).toBe(1000);
		expect(result.fee_micro_usd).toBe(70);
		expect(result.total_micro_usd).toBe(1070);
	});

	it('normalizeSiteUrl strips path/query and lowercases host', () => {
		expect(normalizeSiteUrl('https://Example.COM/wp-admin/?x=1')).toBe('https://example.com');
		expect(normalizeSiteUrl('http://localhost:8000/')).toBe('http://localhost:8000');
	});

	it('normalizeSiteUrl rejects non-http schemes', () => {
		expect(() => normalizeSiteUrl('ftp://example.com')).toThrow();
		expect(() => normalizeSiteUrl('javascript:alert(1)')).toThrow();
	});

	it('sha256Hex produces a stable 64-char hex digest', async () => {
		const hash = await sha256Hex('hello');
		expect(hash).toHaveLength(64);
		expect(hash).toBe(
			'2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824'
		);
	});

	it('generateConnectToken returns a prefixed high-entropy string', () => {
		const a = generateConnectToken();
		const b = generateConnectToken();
		expect(a).toMatch(/^nvc_[A-Za-z0-9_-]{40,}$/);
		expect(a).not.toBe(b);
	});

	it('timingSafeEqual returns true for equal strings only', () => {
		expect(timingSafeEqual('abc', 'abc')).toBe(true);
		expect(timingSafeEqual('abc', 'abd')).toBe(false);
		expect(timingSafeEqual('abc', 'abcd')).toBe(false);
	});
});

describe('billing', () => {
	it('computeBilling reads MARKUP_RATE from env', () => {
		const result = computeBilling(FAKE_ENV as Env, 1.5);
		expect(result.wholesale_micro_usd).toBe(1_500_000);
		expect(result.fee_micro_usd).toBe(105_000);
		expect(result.total_micro_usd).toBe(1_605_000);
	});

	it('computeBilling falls back to 7% on bad MARKUP_RATE', () => {
		const result = computeBilling({ MARKUP_RATE: 'bogus' } as Env, 1);
		expect(result.fee_micro_usd).toBe(70_000);
	});

	it('billingHeaders renders all three columns to 6 decimal places', () => {
		const headers = billingHeaders({
			wholesale_micro_usd: 1_500_000,
			fee_micro_usd: 105_000,
			total_micro_usd: 1_605_000,
		});
		expect(headers['X-NV-Wholesale-Cost']).toBe('1.500000');
		expect(headers['X-NV-Service-Fee']).toBe('0.105000');
		expect(headers['X-NV-Total-Charged']).toBe('1.605000');
	});
});
