/**
 * Generic list/table view for any collection.
 */
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { useCollection, useDocumentDelete } from '../hooks/useCollection';
import type { CollectionConfig } from '../config/collections';

interface ListViewProps {
  config: CollectionConfig;
  onEdit: (id: number) => void;
  onCreate: () => void;
}

export function ListView({ config, onEdit, onCreate }: ListViewProps) {
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = useCollection<any>(config.slug, page, 20);
  const deleteMutation = useDocumentDelete(config.slug);

  if (isLoading) {
    return <p>Loading {config.pluralLabel}...</p>;
  }

  if (error) {
    return <p style={{ color: 'red' }}>Error loading {config.pluralLabel}.</p>;
  }

  const docs = data?.docs ?? [];
  const totalPages = data?.totalPages ?? 1;

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure you want to delete this item?')) return;
    deleteMutation.mutate(id);
  };

  const getCellValue = (doc: any, col: string): string => {
    const val = doc[col];
    if (val === null || val === undefined) return '—';
    if (typeof val === 'boolean') return val ? 'Yes' : 'No';
    if (typeof val === 'object') return val.name ?? val.title ?? val.code ?? String(val);
    return String(val);
  };

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h2>{config.pluralLabel}</h2>
        <Button variant="primary" onClick={onCreate}>
          Add {config.label}
        </Button>
      </div>

      <table className="wp-list-table widefat fixed striped" style={{ width: '100%' }}>
        <thead>
          <tr>
            <th style={{ width: 50 }}>ID</th>
            {config.defaultColumns.map((col) => (
              <th key={col}>{col}</th>
            ))}
            <th style={{ width: 120 }}>Actions</th>
          </tr>
        </thead>
        <tbody>
          {docs.map((doc: any) => (
            <tr key={doc.id}>
              <td>{doc.id}</td>
              {config.defaultColumns.map((col) => (
                <td key={col}>{getCellValue(doc, col)}</td>
              ))}
              <td>
                <Button variant="secondary" onClick={() => onEdit(doc.id)} style={{ marginRight: 4 }}>
                  Edit
                </Button>
                <Button variant="tertiary" isDestructive onClick={() => handleDelete(doc.id)}>
                  Delete
                </Button>
              </td>
            </tr>
          ))}
          {docs.length === 0 && (
            <tr>
              <td colSpan={config.defaultColumns.length + 2} style={{ textAlign: 'center', padding: 24 }}>
                No {config.pluralLabel} found. Click "Add {config.label}" to create one.
              </td>
            </tr>
          )}
        </tbody>
      </table>

      {totalPages > 1 && (
        <div style={{ marginTop: 16, display: 'flex', gap: 8, alignItems: 'center' }}>
          <Button
            variant="secondary"
            disabled={page <= 1}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
          >
            Previous
          </Button>
          <span>
            Page {page} of {totalPages} ({data?.totalDocs ?? 0} total)
          </span>
          <Button
            variant="secondary"
            disabled={page >= totalPages}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </Button>
        </div>
      )}
    </div>
  );
}
