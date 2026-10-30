/**
 * Schema generation — the one-config contract: required/min/max rules
 * derive from FieldConfig exactly as the REST layer enforces them.
 */
import { describe, expect, it } from 'vitest';
import { buildSchema } from '../formSchema';
import type { CollectionConfig } from '../collections';

const productConfig: CollectionConfig = {
	slug: 'products',
	label: 'Product',
	pluralLabel: 'Products',
	type: 'collection',
	defaultColumns: ['name', 'price'],
	fields: [
		{ name: 'name', type: 'text', label: 'Product Name', required: true },
		{ name: 'price', type: 'number', label: 'Price', required: true, min: 0, step: 0.01 },
		{ name: 'rating', type: 'number', label: 'Rating', min: 0, max: 5 },
		{ name: 'category', type: 'relationship', label: 'Category', relation: 'categories', required: true },
		{ name: 'isBestseller', type: 'checkbox', label: 'Bestseller' },
		{ name: 'description', type: 'textarea', label: 'Description', required: true },
	],
};

describe('buildSchema', () => {
	it('accepts a valid product payload', () => {
		const schema = buildSchema(productConfig);
		const result = schema.safeParse({
			name: 'Lounge Chair',
			price: 1299,
			category: { id: 3 },
			isBestseller: false,
			description: 'Comfortable.',
		});
		expect(result.success).toBe(true);
	});

	it('rejects empty required text fields with the field label', () => {
		const schema = buildSchema(productConfig);
		const result = schema.safeParse({
			name: '',
			price: 10,
			category: { id: 3 },
			isBestseller: false,
			description: 'x',
		});
		expect(result.success).toBe(false);
		if (!result.success) {
			const fieldErrors = result.error.flatten().fieldErrors;
			expect(fieldErrors.name?.[0]).toContain('Product Name');
		}
	});

	it('coerces numeric strings and enforces min/max', () => {
		const schema = buildSchema(productConfig);
		const base = {
			name: 'A',
			category: { id: 1 },
			isBestseller: false,
			description: 'x',
		};

		expect(schema.safeParse({ ...base, price: '100' }).success).toBe(true);
		expect(schema.safeParse({ ...base, price: -1 }).success).toBe(false);
		expect(schema.safeParse({ ...base, price: 10, rating: 6 }).success).toBe(false);
	});

	it('rejects a missing required relationship', () => {
		const schema = buildSchema(productConfig);
		const result = schema.safeParse({ name: 'A', price: 10, description: 'x' });
		expect(result.success).toBe(false);
		if (!result.success) {
			expect(result.error.flatten().fieldErrors.category?.[0]).toContain('Category');
		}
	});
});
