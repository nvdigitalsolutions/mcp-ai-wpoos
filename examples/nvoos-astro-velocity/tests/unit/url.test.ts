import { describe, expect, it } from 'vitest';
import { slugify, productPath, categoryPath, absoluteUrl } from '../../src/lib/url';

describe('slugify', () => {
	it('lowercases and hyphenates', () => {
		expect(slugify('Aurora 3-Seater Sofa')).toBe('aurora-3-seater-sofa');
	});

	it('strips apostrophes and punctuation', () => {
		expect(slugify("Lena's Chair!")).toBe('lenas-chair');
	});

	it('falls back for empty names', () => {
		expect(slugify('')).toBe('item');
	});
});

describe('path builders', () => {
	it('builds id-keyed product paths with slug suffixes (D5)', () => {
		expect(productPath(42, 'Aurora Sofa')).toBe('/products/42/aurora-sofa/');
		expect(categoryPath(1, 'Sofas')).toBe('/categories/1/sofas/');
	});
});

describe('absoluteUrl', () => {
	it('joins site and path without double slashes', () => {
		expect(absoluteUrl('https://store.nvoos.cloud/', '/products/1/x/')).toBe(
			'https://store.nvoos.cloud/products/1/x/'
		);
	});
});
