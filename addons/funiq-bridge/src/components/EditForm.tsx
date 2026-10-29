/**
 * Generic edit/create form — renders the correct field component based on
 * config, validated with a zod schema generated from the same config.
 *
 * The schema is derived from the field definitions (the Payload pattern:
 * one config drives the API, the admin, and the validation), so a required
 * or min/max change can never drift between the server and this form.
 * Server errors (REST rejection messages) still surface as a Notice; field
 * errors from the schema render under the offending field with
 * aria-invalid set on the control.
 */
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { useDocument, useDocumentMutation } from '../hooks/useCollection';
import { buildSchema } from '../config/formSchema';
import { TextField } from './fields/TextField';
import { NumberField } from './fields/NumberField';
import { TextareaField } from './fields/TextareaField';
import { CheckboxField } from './fields/CheckboxField';
import { DateField } from './fields/DateField';
import { ImageUpload } from './fields/ImageUpload';
import { RelationshipField } from './fields/RelationshipField';
import type { CollectionConfig, FieldConfig } from '../config/collections';

interface EditFormProps {
	config: CollectionConfig;
	id: number | null; // null = create mode
	/** Server-declared read-only collections render no form at all. */
	readOnly?: boolean;
	onBack: () => void;
}

type FormErrors = Record<string, string | undefined>;

export function EditForm({ config, id, readOnly = false, onBack }: EditFormProps) {
	const isCreate = id === null;
	const { data: doc, isLoading } = useDocument<any>(
		config.slug,
		isCreate ? null : id
	);
	const mutation = useDocumentMutation(config.slug);

	const [formData, setFormData] = useState<Record<string, any>>({});
	const [fieldErrors, setFieldErrors] = useState<FormErrors>({});
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState<string | null>(null);

	// The schema follows the config — memoized so it only rebuilds when the
	// collection definition changes (the RHF/zodResolver identity lesson).
	const schema = useMemo(() => buildSchema(config), [config]);

	// Populate form when document loads.
	useEffect(() => {
		if (doc && !isCreate) {
			// Normalize: map imageId / imagesIds (numeric attachment IDs) onto
			// the upload/array fields so the ImageUpload component receives an
			// attachment ID instead of a URL string.
			const norm = { ...doc };
			if (norm.imageId !== undefined && norm.imageId !== null) {
				norm.image = norm.imageId;
			}
			if (norm.imagesIds !== undefined && Array.isArray(norm.imagesIds)) {
				norm.images = norm.imagesIds;
			}
			setFormData(norm);
		} else if (isCreate) {
			// Initialize defaults.
			const defaults: Record<string, any> = {};
			config.fields.forEach((f) => {
				if (f.type === 'checkbox') defaults[f.name] = false;
				else if (f.type === 'number') defaults[f.name] = f.min ?? 0;
				else if (f.type === 'array') defaults[f.name] = [];
				else defaults[f.name] = '';
			});
			setFormData(defaults);
		}
	}, [doc, isCreate, config.fields]);

	const handleChange = useCallback((name: string, value: any) => {
		setFormData((prev) => ({ ...prev, [name]: value }));
		// Clear the field error as soon as the user edits the field.
		setFieldErrors((prev) => ({ ...prev, [name]: undefined }));
	}, []);

	const handleSubmit = async (e: React.FormEvent) => {
		e.preventDefault();
		setSaving(true);
		setError(null);
		setFieldErrors({});

		// Client-side schema gate first — the server stays authoritative, but
		// field-level messages are far more actionable than a 400 round-trip.
		const parsed = schema.safeParse(formData);
		if (!parsed.success) {
			const flattened = parsed.error.flatten().fieldErrors as Record<
				string,
				string[] | undefined
			>;
			const errors: FormErrors = {};
			Object.entries(flattened).forEach(([field, messages]) => {
				if (messages && messages.length > 0) {
					errors[field] = messages[0];
				}
			});
			setFieldErrors(errors);
			setError('Please fix the highlighted fields.');
			setSaving(false);
			return;
		}

		try {
			if (isCreate) {
				await mutation.mutateAsync({ data: formData });
			} else {
				await mutation.mutateAsync({ id: id!, data: formData });
			}
			onBack();
		} catch (err: any) {
			setError(err?.message ?? 'Save failed. Please try again.');
		} finally {
			setSaving(false);
		}
	};

	if (readOnly) {
		return (
			<div>
				<Notice status="warning" isDismissible={false}>
					This collection is managed by WooCommerce and is read-only.
				</Notice>
				<Button variant="tertiary" onClick={onBack} style={{ marginTop: 16 }}>
					← Back to list
				</Button>
			</div>
		);
	}

	if (isLoading && !isCreate) {
		return <p>Loading...</p>;
	}

	const renderField = (field: FieldConfig) => {
		const fieldError = fieldErrors[field.name];

		switch (field.type) {
			case 'text':
				return (
					<TextField
						key={field.name}
						label={field.label}
						value={formData[field.name] ?? ''}
						required={field.required}
						error={fieldError}
						onChange={(v) => handleChange(field.name, v)}
					/>
				);
			case 'number':
				return (
					<NumberField
						key={field.name}
						label={field.label}
						value={formData[field.name] ?? ''}
						required={field.required}
						min={field.min}
						max={field.max}
						step={field.step}
						error={fieldError}
						onChange={(v) => handleChange(field.name, v)}
					/>
				);
			case 'textarea':
				return (
					<TextareaField
						key={field.name}
						label={field.label}
						value={formData[field.name] ?? ''}
						required={field.required}
						error={fieldError}
						onChange={(v) => handleChange(field.name, v)}
					/>
				);
			case 'checkbox':
				return (
					<CheckboxField
						key={field.name}
						label={field.label}
						checked={!!formData[field.name]}
						onChange={(v) => handleChange(field.name, v)}
					/>
				);
			case 'date':
				return (
					<DateField
						key={field.name}
						label={field.label}
						value={formData[field.name] ?? ''}
						required={field.required}
						error={fieldError}
						onChange={(v) => handleChange(field.name, v)}
					/>
				);
			case 'upload':
				return (
					<ImageUpload
						key={field.name}
						label={field.label}
						value={formData[field.name] ?? null}
						error={fieldError}
						onChange={(v) => handleChange(field.name, v)}
					/>
				);
			case 'relationship':
				return (
					<RelationshipField
						key={field.name}
						label={field.label}
						relation={field.relation!}
						hasMany={field.hasMany ?? false}
						value={formData[field.name] ?? (field.hasMany ? [] : null)}
						required={field.required}
						error={fieldError}
						onChange={(v) => handleChange(field.name, v)}
					/>
				);
			default:
				return null;
		}
	};

	return (
		<div>
			<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
				<h2>{isCreate ? `New ${config.label}` : `Edit ${config.label}`}</h2>
				<Button variant="tertiary" onClick={onBack}>← Back to list</Button>
			</div>

			{error && <Notice status="error" onRemove={() => setError(null)}>{error}</Notice>}

			<form onSubmit={handleSubmit} style={{ maxWidth: 640 }} noValidate>
				{config.fields.map(renderField)}

				<div style={{ marginTop: 24, display: 'flex', gap: 8 }}>
					<Button variant="primary" type="submit" isBusy={saving} disabled={saving}>
						{isCreate ? 'Create' : 'Save'}
					</Button>
					<Button variant="tertiary" type="button" onClick={onBack}>
						Cancel
					</Button>
				</div>
			</form>
		</div>
	);
}
