import { describe, expect, it } from 'vitest';
import { formatPrice, resolveCurrency } from '../../src/lib/commerce/money';

describe('money', () => {
	it('formats with the configured currency (Q7: USD default)', () => {
		expect(resolveCurrency()).toBe('USD');
		expect(formatPrice(1299)).toBe('$1,299.00');
	});

	it('formats zero and fractions', () => {
		expect(formatPrice(0)).toBe('$0.00');
		expect(formatPrice(29.5)).toBe('$29.50');
	});
});
