import { describe, expect, it } from 'vitest';
import { googleFontsUrl, webfontFamilies } from '../src/webfonts';

describe('webfontFamilies', () => {
	it('returns named families and skips system stacks + CSS stacks', () => {
		expect(webfontFamilies(['Rubik', 'system-ui, -apple-system, sans-serif', 'arial'])).toEqual([
			'Rubik',
		]);
	});

	it('dedupes case-insensitively and flattens arrays', () => {
		expect(webfontFamilies([['Inter'], 'inter', 'Lora'])).toEqual(['Inter', 'Lora']);
	});

	it('ignores empty and undefined entries', () => {
		expect(webfontFamilies(['', undefined, null, '  '])).toEqual([]);
	});
});

describe('googleFontsUrl', () => {
	it('builds the css2 URL with the weight axis', () => {
		expect(googleFontsUrl(['Rubik', 'Lora'], [400, 700])).toBe(
			'https://fonts.googleapis.com/css2?family=Rubik:wght@400;700&family=Lora:wght@400;700&display=swap'
		);
	});

	it('returns undefined when there is nothing to load (the hermetic default)', () => {
		expect(googleFontsUrl([])).toBeUndefined();
		expect(googleFontsUrl(webfontFamilies(['system-ui', 'segoe']))).toBeUndefined();
	});
});
