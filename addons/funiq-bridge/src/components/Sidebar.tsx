/**
 * Sidebar navigation — lists all collections.
 */
import { collections } from '../config/collections';
import type { CollectionSlug } from '../types';

interface SidebarProps {
  activeSlug: CollectionSlug;
  onSelect: (slug: CollectionSlug) => void;
}

export function Sidebar({ activeSlug, onSelect }: SidebarProps) {
  return (
    <nav>
      <div style={{ padding: '0 16px 16px', fontWeight: 700, fontSize: 16 }}>
        Funiq CMS
      </div>
      {collections.map((col) => (
        <button
          key={col.slug}
          onClick={() => onSelect(col.slug)}
          style={{
            display: 'block',
            width: '100%',
            textAlign: 'left',
            padding: '10px 16px',
            border: 'none',
            background: col.slug === activeSlug ? '#007cba' : 'transparent',
            color: '#fff',
            cursor: 'pointer',
            fontSize: 14,
          }}
        >
          {col.pluralLabel}
        </button>
      ))}
    </nav>
  );
}
