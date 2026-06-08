/**
 * Generic edit/create form — renders the correct field component based on config.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { useDocument, useDocumentMutation } from '../hooks/useCollection';
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
  onBack: () => void;
}

export function EditForm({ config, id, onBack }: EditFormProps) {
  const isCreate = id === null;
  const { data: doc, isLoading } = useDocument<any>(
    config.slug,
    isCreate ? null : id
  );
  const mutation = useDocumentMutation(config.slug);

  const [formData, setFormData] = useState<Record<string, any>>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

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
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);

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

  if (isLoading && !isCreate) {
    return <p>Loading...</p>;
  }

  const renderField = (field: FieldConfig) => {
    switch (field.type) {
      case 'text':
        return (
          <TextField
            key={field.name}
            label={field.label}
            value={formData[field.name] ?? ''}
            required={field.required}
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
            onChange={(v) => handleChange(field.name, v)}
          />
        );
      case 'upload':
        return (
          <ImageUpload
            key={field.name}
            label={field.label}
            value={formData[field.name] ?? null}
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

      <form onSubmit={handleSubmit} style={{ maxWidth: 640 }}>
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
