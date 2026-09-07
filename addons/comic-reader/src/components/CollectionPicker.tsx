/**
 * NV oOS Comic Reader — Collection Picker
 *
 * Dialog for adding a comic to a collection or creating a new collection
 * (Komga's collections/read-lists affordance).
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.4.0
 */

import { useEffect, useRef, useState } from 'react';
import type { ComicCollection } from '../api/comic-api';
import {
	createCollection,
	addToCollection,
	removeFromCollection,
} from '../api/comic-api';
import { t } from '../utils/i18n';

interface CollectionPickerProps {
	comicId: number;
	collections: ComicCollection[];
	onClose: () => void;
	onChanged: (collections: ComicCollection[]) => void;
}

export function CollectionPicker({
	comicId,
	collections,
	onClose,
	onChanged,
}: CollectionPickerProps) {
	const dialogRef = useRef<HTMLDivElement>(null);
	const [newName, setNewName] = useState('');
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		const previouslyFocused = document.activeElement as HTMLElement | null;
		dialogRef.current?.focus();

		const handleKeyDown = (e: KeyboardEvent) => {
			if (e.key === 'Escape') {
				e.preventDefault();
				onClose();
			}
		};
		document.addEventListener('keydown', handleKeyDown);

		return () => {
			document.removeEventListener('keydown', handleKeyDown);
			previouslyFocused?.focus();
		};
	}, [onClose]);

	const handleToggle = async (collection: ComicCollection) => {
		setBusy(true);
		setError(null);
		try {
			const isMember = collection.items.includes(comicId);
			if (isMember) {
				await removeFromCollection(collection.id, comicId);
			} else {
				await addToCollection(collection.id, comicId);
			}
			onChanged(
				collections.map((c) =>
					c.id === collection.id
						? {
								...c,
								items: isMember
									? c.items.filter((i) => i !== comicId)
									: [...c.items, comicId],
							}
						: c
				)
			);
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorSave'));
		} finally {
			setBusy(false);
		}
	};

	const handleCreate = async () => {
		const name = newName.trim();
		if (!name) return;

		setBusy(true);
		setError(null);
		try {
			const created = await createCollection(name);
			setNewName('');
			await addToCollection(created.id, comicId);
			onChanged([
				...collections,
				{ ...created, items: [comicId] },
			]);
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorSave'));
		} finally {
			setBusy(false);
		}
	};

	return (
		<div className="nvoos-cr-overlay" role="dialog" aria-modal="true" aria-label={t('collections')}>
			<div className="nvoos-cr-overlay-header">
				<h2 className="nvoos-cr-overlay-title">{t('collections')}</h2>
				<button className="nvoos-cr-btn" onClick={onClose} aria-label={t('close')}>
					×
				</button>
			</div>
			<div className="nvoos-cr-settings" ref={dialogRef} tabIndex={-1}>
				{collections.map((collection) => {
					const isMember = collection.items.includes(comicId);
					return (
						<div className="nvoos-cr-settings-row" key={collection.id}>
							<span className="nvoos-cr-settings-label">
								{collection.name} ({collection.items.length})
							</span>
							<button
								className={`nvoos-cr-btn ${
									isMember ? 'nvoos-cr-btn--active' : ''
								}`}
								onClick={() => handleToggle(collection)}
								disabled={busy}
							>
								{isMember ? '✓' : '+'}
							</button>
						</div>
					);
				})}

				<div className="nvoos-cr-settings-row">
					<label className="nvoos-cr-settings-label" htmlFor="nvoos-cr-new-collection">
						{t('newCollection')}
					</label>
					<div className="nvoos-cr-new-collection">
						<input
							id="nvoos-cr-new-collection"
							className="nvoos-cr-settings-select"
							type="text"
							value={newName}
							onChange={(e) => setNewName(e.target.value)}
						/>
						<button
							className="nvoos-cr-btn nvoos-cr-btn-primary"
							onClick={handleCreate}
							disabled={busy || !newName.trim()}
						>
							+
						</button>
					</div>
				</div>

				{error && (
					<div className="nvoos-cr-upload-error" role="alert">
						<p>{error}</p>
					</div>
				)}
			</div>
		</div>
	);
}
