/**
 * NV oOS Comic Reader — Comic Library Component
 *
 * Grid view of all comics in the WordPress Media Library.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useEffect, useCallback } from 'react';
import {
	fetchComics,
	deleteComic,
	formatFileSize,
	type ComicItem,
} from '../api/comic-api';

interface ComicLibraryProps {
	onOpenComic: (comic: ComicItem) => void;
}

export function ComicLibrary({ onOpenComic }: ComicLibraryProps) {
	const [comics, setComics] = useState<ComicItem[]>([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [deleting, setDeleting] = useState<number | null>(null);

	const t = (key: string): string =>
		window.NVOOS_COMIC_READER?.i18n?.[key] || key;

	const loadComics = useCallback(async () => {
		setLoading(true);
		setError(null);
		try {
			const data = await fetchComics();
			setComics(data.comics);
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorLoad'));
		} finally {
			setLoading(false);
		}
	}, [t]);

	useEffect(() => {
		loadComics();
	}, [loadComics]);

	const handleDelete = useCallback(
		async (id: number) => {
			if (!window.confirm(t('confirmDelete'))) return;

			setDeleting(id);
			try {
				await deleteComic(id);
				setComics((prev) => prev.filter((c) => c.id !== id));
			} catch (err) {
				alert(err instanceof Error ? err.message : t('errorLoad'));
			} finally {
				setDeleting(null);
			}
		},
		[t]
	);

	if (loading) {
		return (
			<div className="nvoos-cr-loading" role="status">
				<div className="nvoos-cr-spinner" />
				<span>{t('loading')}</span>
			</div>
		);
	}

	if (error) {
		return (
			<div className="nvoos-cr-error" role="alert">
				<p>{error}</p>
				<button className="nvoos-cr-btn" onClick={loadComics}>
					Retry
				</button>
			</div>
		);
	}

	if (comics.length === 0) {
		return (
			<div className="nvoos-cr-empty">
				<div className="nvoos-cr-empty-icon">📚</div>
				<p>{t('noComics')}</p>
				<p className="nvoos-cr-empty-hint">{t('dropHint')}</p>
			</div>
		);
	}

	return (
		<div className="nvoos-cr-library">
			<div className="nvoos-cr-grid">
				{comics.map((comic) => (
					<div key={comic.id} className="nvoos-cr-card">
						<button
							className="nvoos-cr-card-cover"
							onClick={() => onOpenComic(comic)}
							aria-label={comic.title}
						>
							{comic.cover_url ? (
								<img
									src={comic.cover_url}
									alt={comic.title}
									loading="lazy"
								/>
							) : (
								<div className="nvoos-cr-card-placeholder">
									<span className="nvoos-cr-card-format">
										{comic.format}
									</span>
								</div>
							)}
						</button>
						<div className="nvoos-cr-card-info">
							<h3 className="nvoos-cr-card-title" title={comic.title}>
								{comic.title}
							</h3>
							<p className="nvoos-cr-card-meta">
								{comic.format} &middot; {formatFileSize(comic.file_size)}
							</p>
						</div>
						<button
							className="nvoos-cr-card-delete"
							onClick={() => handleDelete(comic.id)}
							disabled={deleting === comic.id}
							aria-label={t('deleteComic')}
							title={t('deleteComic')}
						>
							{deleting === comic.id ? '…' : '×'}
						</button>
					</div>
				))}
			</div>
		</div>
	);
}
