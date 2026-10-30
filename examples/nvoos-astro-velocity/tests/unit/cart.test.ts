import { describe, expect, it, beforeEach } from 'vitest';
import {
	addToCart,
	cartCount,
	cartLines,
	clearCart,
	removeFromCart,
	setLineQty,
} from '../../src/lib/store/cart';
import type { CartLine } from '../../src/lib/store/cart';

const line = (productId: number): Omit<CartLine, 'qty'> => ({
	productId,
	name: `Product ${productId}`,
	price: 10,
	image: '',
});

describe('cart store', () => {
	beforeEach(() => {
		clearCart();
	});

	it('adds lines and tracks the count', () => {
		addToCart(line(1));
		addToCart(line(1), 2);
		addToCart(line(2));
		expect(cartCount.get()).toBe(4);
		expect(cartLines.get()[1].qty).toBe(3);
	});

	it('removes lines', () => {
		addToCart(line(1));
		removeFromCart(1);
		expect(cartCount.get()).toBe(0);
	});

	it('adjusts quantities and removes at zero', () => {
		addToCart(line(1), 3);
		setLineQty(1, 5);
		expect(cartCount.get()).toBe(5);
		setLineQty(1, 0);
		expect(cartCount.get()).toBe(0);
	});

	it('clears everything', () => {
		addToCart(line(1));
		addToCart(line(2));
		clearCart();
		expect(cartCount.get()).toBe(0);
	});
});
