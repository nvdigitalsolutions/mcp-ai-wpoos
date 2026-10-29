/**
 * Root component — layout shell with sidebar navigation.
 */
import { useState } from '@wordpress/element';
import { Layout } from './components/Layout';
import { Sidebar } from './components/Sidebar';
import { ListView } from './components/ListView';
import { EditForm } from './components/EditForm';
import { ManagersView } from './components/ManagersView';
import { collections } from './config/collections';
import type { NavSlug } from './types';

export function AdminApp() {
  const [activeSlug, setActiveSlug] = useState<NavSlug>('products');
  const [editingId, setEditingId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);

  const activeCollection = collections.find((c) => c.slug === activeSlug);

  const handleEdit = (id: number) => {
    setEditingId(id);
    setCreating(false);
  };

  const handleCreate = () => {
    setEditingId(null);
    setCreating(true);
  };

  const handleBack = () => {
    setEditingId(null);
    setCreating(false);
  };

  return (
    <Layout
      sidebar={<Sidebar activeSlug={activeSlug} onSelect={(s) => { setActiveSlug(s); handleBack(); }} />}
    >
      {activeSlug === 'managers' || !activeCollection ? (
        <ManagersView />
      ) : editingId !== null || creating ? (
        <EditForm
          config={activeCollection}
          id={editingId}
          onBack={handleBack}
        />
      ) : (
        <ListView
          config={activeCollection}
          onEdit={handleEdit}
          onCreate={handleCreate}
        />
      )}
    </Layout>
  );
}
