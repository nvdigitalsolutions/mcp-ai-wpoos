/**
 * Root component — layout shell with catalogue-driven navigation.
 *
 * Routing is state-based (no URL router), so the "route guard" is a simple
 * fallback: an unknown/hidden slug renders the first visible catalogue
 * entry instead of an empty pane (the console's RequireGate equivalent).
 */
import { useState } from '@wordpress/element';
import { Layout } from './components/Layout';
import { Sidebar } from './components/Sidebar';
import { ListView } from './components/ListView';
import { EditForm } from './components/EditForm';
import { ManagersView } from './components/ManagersView';
import { collections } from './config/collections';
import { defaultSlug, isCollectionWritable, isNavEntryVisible, navEntryFor } from './auth/permissions';
import type { NavSlug } from './types';

export function AdminApp() {
	const [activeSlug, setActiveSlug] = useState<NavSlug>(() => defaultSlug());
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

	const handleSelect = (slug: NavSlug) => {
		const entry = navEntryFor(slug);
		if (!entry || !isNavEntryVisible(entry)) {
			return;
		}
		setActiveSlug(slug);
		handleBack();
	};

	return (
		<Layout sidebar={<Sidebar activeSlug={activeSlug} onSelect={handleSelect} />}>
			{activeSlug === 'managers' || !activeCollection ? (
				<ManagersView />
			) : editingId !== null || creating ? (
				<EditForm
					config={activeCollection}
					id={editingId}
					readOnly={!isCollectionWritable(activeCollection.slug)}
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
