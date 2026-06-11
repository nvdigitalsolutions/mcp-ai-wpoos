/**
 * Relationship field — single or hasMany selector for related collections.
 */
import { useCollection } from '../../hooks/useCollection';
import type { CollectionSlug } from '../../types';

interface RelationshipFieldProps {
  label: string;
  relation: string;
  hasMany: boolean;
  /** For singles: { id, name } | null. For hasMany: Array<{ id, name }>. */
  value: any;
  required?: boolean;
  onChange: (value: any) => void;
}

export function RelationshipField({ label, relation, hasMany, value, required, onChange }: RelationshipFieldProps) {
  const { data } = useCollection<any>(relation as CollectionSlug, 1, 100);

  const options = data?.docs ?? [];

  if (hasMany) {
    const selectedIds: number[] = Array.isArray(value)
      ? value.map((v: any) => (typeof v === 'object' ? v.id : v))
      : [];

    const handleToggle = (id: number) => {
      if (selectedIds.includes(id)) {
        onChange(value.filter((v: any) => (v.id ?? v) !== id));
      } else {
        const item = options.find((o: any) => o.id === id);
        onChange([...(Array.isArray(value) ? value : []), item ? { id: item.id, name: item.name } : id]);
      }
    };

    return (
      <div style={{ marginBottom: 16 }}>
        <label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>
          {label}{required && ' *'}
        </label>
        <div style={{ maxHeight: 200, overflowY: 'auto', border: '1px solid #ddd', padding: 8, borderRadius: 4 }}>
          {options.map((opt: any) => (
            <label key={opt.id} style={{ display: 'block', marginBottom: 4 }}>
              <input
                type="checkbox"
                checked={selectedIds.includes(opt.id)}
                onChange={() => handleToggle(opt.id)}
                style={{ marginRight: 8 }}
              />
              {opt.name ?? opt.title ?? opt.code ?? `#${opt.id}`}
            </label>
          ))}
          {options.length === 0 && <p style={{ color: '#999', margin: 0 }}>No {relation} available.</p>}
        </div>
      </div>
    );
  }

  // Single relationship.
  const currentId = value && typeof value === 'object' ? value.id : value;

  return (
    <div style={{ marginBottom: 16 }}>
      <label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>
        {label}{required && ' *'}
      </label>
      <select
        value={currentId ?? ''}
        required={required}
        onChange={(e) => {
          const id = e.target.value ? parseInt(e.target.value, 10) : null;
          if (id) {
            const item = options.find((o: any) => o.id === id);
            onChange(item ? { id: item.id, name: item.name ?? item.title ?? item.code } : id);
          } else {
            onChange(null);
          }
        }}
        style={{ width: '100%', maxWidth: 400 }}
      >
        <option value="">— Select {relation} —</option>
        {options.map((opt: any) => (
          <option key={opt.id} value={opt.id}>
            {opt.name ?? opt.title ?? opt.code ?? `#${opt.id}`}
          </option>
        ))}
      </select>
    </div>
  );
}
