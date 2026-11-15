// og.test.ts — Proposal 104 Q5: the pure OG card builder (the satori/resvg
// render path is covered by the e2e + smoke image assertions).
import { describe, expect, it } from 'vitest';
import { ogCardElement, ogCardTokens } from '../../src/lib/seo/og';
import type { Product } from '../../src/lib/commerce/types';

const product = {
	id: 1,
	name: 'Aurora 3-Seater Sofa',
	price: 1299,
	brand: { id: 1, name: 'NV Home' },
	category: { id: 1, name: 'Sofas' },
} as unknown as Product;

describe('ogCardElement', () => {
	const element = ogCardElement(product, ogCardTokens());

	it('is a single root node', () => {
		expect(element.type).toBe('div');
	});

	it('carries the product name, price and subtitle', () => {
		const texts: string[] = [];
		const walk = (node: unknown): void => {
			if (Array.isArray(node)) {
				node.forEach(walk);
				return;
			}
			if (node && typeof node === 'object' && 'props' in node) {
				const props = (node as { props?: { children?: unknown } }).props ?? {};
				if (typeof props.children === 'string') {
					texts.push(props.children);
				} else if (props.children !== undefined) {
					walk(props.children);
				}
			}
		};
		walk(element);
		expect(texts).toContain('Aurora 3-Seater Sofa');
		expect(texts.some((text) => text.includes('1,299'))).toBe(true);
		expect(texts.some((text) => text.includes('NV Home') && text.includes('Sofas'))).toBe(true);
	});
});

describe('ogCardTokens', () => {
	it('coerces the generated semantic layer into card tokens', () => {
		const tokens = ogCardTokens();
		expect(tokens.accent).toMatch(/^#/);
		expect(tokens.ink).toMatch(/^#/);
		expect(tokens.surface).toMatch(/^#/);
	});
});
