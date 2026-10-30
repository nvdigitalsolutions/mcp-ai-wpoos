/**
 * zod schema generation for collection forms.
 *
 * The schema is derived from the same FieldConfig that drives the REST
 * contract and the admin UI (the Payload pattern: one config, three
 * consumers). Keeping it in its own module lets tests validate the schema
 * without importing the component tree (@wordpress/components).
 */
import { z } from 'zod';
import type { CollectionConfig, FieldConfig } from './collections';

/** A relationship value as the SPA sends it: { id, name } or a bare ID. */
const relationshipValue = z.union([
	z.number().positive(),
	z.object({ id: z.number().positive() }),
]);

/** An upload value: attachment ID, URL string, or unset. */
const uploadValue = z.union([z.number().positive(), z.string(), z.null()]);

/** Structural view of a schema that carries zod 4's `.refine()` method. */
type Refineable = {
	refine(check: (v: unknown) => boolean, message?: string): z.ZodTypeAny;
};

/**
 * Apply the required refinement per field type.
 *
 * Number requiredness is baked into the inner schema (the preprocess maps
 * empty → undefined, which the required inner number rejects); everything
 * else uses an explicit null/undefined union + a refine so the field's
 * label lands in the message. (zod 4 skips refinements on undefined for
 * `.nullish()` schemas, so the union must name null/undefined explicitly.)
 */
function refineRequired(base: z.ZodTypeAny, field: FieldConfig): z.ZodTypeAny {
	const message = `${field.label} is required.`;

	switch (field.type) {
		case 'number':
		case 'checkbox':
			return base;
		case 'relationship':
			if (field.hasMany) {
				return (base as z.ZodArray<any>).min(1, message);
			}
			return (base as Refineable).refine(
				(v) => v !== null && v !== undefined,
				message
			);
		case 'upload':
			return (base as Refineable).refine(
				(v) =>
					(typeof v === 'number' && v > 0) ||
					(typeof v === 'string' && v !== ''),
				message
			);
		default:
			return (base as z.ZodString).min(1, message);
	}
}

/**
 * Build the zod schema for a collection's fields.
 *
 * Coerces numbers (empty string → undefined) the way the field components
 * already normalize values; required fields reject empty/absent values.
 */
export function buildSchema(config: CollectionConfig): z.ZodObject<any> {
	const shape: Record<string, z.ZodTypeAny> = {};

	config.fields.forEach((field: FieldConfig) => {
		let base: z.ZodTypeAny;

		switch (field.type) {
			case 'number': {
				let numberSchema = z.number({
					error: field.required
						? `${field.label} is required.`
						: `${field.label} must be a number.`,
				});
				if (field.min !== undefined) {
					numberSchema = numberSchema.min(
						field.min,
						`${field.label} must be at least ${field.min}.`
					);
				}
				if (field.max !== undefined) {
					numberSchema = numberSchema.max(
						field.max,
						`${field.label} must be at most ${field.max}.`
					);
				}
				base = z.preprocess(
					(v) => (v === '' || v === undefined || v === null ? undefined : Number(v)),
					field.required ? numberSchema : numberSchema.optional()
				);
				break;
			}
			case 'checkbox':
				base = z.boolean();
				break;
			case 'relationship':
				base = field.hasMany
					? z.array(relationshipValue).default([])
					: z.union([relationshipValue, z.null(), z.undefined()]);
				break;
			case 'upload':
				base = z.union([uploadValue, z.undefined()]);
				break;
			case 'array':
				base = z.any();
				break;
			case 'textarea':
			case 'date':
			case 'text':
			default:
				base = z.string();
				break;
		}

		if (field.required) {
			base = refineRequired(base, field);
		}

		shape[field.name] = base;
	});

	return z.object(shape);
}
