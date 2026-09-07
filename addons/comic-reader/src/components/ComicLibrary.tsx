/**
 * NV oOS Comic Reader — Comic Library Component
 *
 * Grid view of all comics in the WordPress Media Library, plus a
 * "Continue reading" shelf driven by local progress records.
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
import {
	readAllLocalProgress,
	progressPercent,
	type ProgressRecord,
} from '../types/progress';
import { t } from '../utils/i18n';

interface ComicLibraryProps {
	onOpenComic: (comic: ComicItem) => void;
}

interface ComicCardProps {
	comic: ComicItem;
	progress: ProgressRecord | null;
	deleting: boolean;
	onOpen: (comic: ComicItem) => void;
	onDelete: (id: number) => void;
}

function ComicCard({ comic, progress, deleting, onOpen, onDelete }: ComicCardProps) {
	const percent = progress ? progressPercent(progress) : 0;

	return (
		<div className="nvoos-cr-card">
			<button
				className="nvoos-cr-card-cover"
				onClick={() => onOpen(comic)}
				aria-label={comic.title}
			>
				{comic.cover_url ? (
					<img src={comic.cover_url} alt={comic.title} loading="lazy" />
				) : (
					<div className="nvoos-cr-card-placeholder">
						<span className="nvoos-cr-card-format">{comic.format}</span>
					</div>
				)}
				{progress && (
					<div
						className="nvoos-cr-card-progress"
						role="progressbar"
						aria-valuenow={percent}
						aria-valuemin={0}
						aria-valuemax={100}
						aria-label={
							progress.completed
								? t('completedLabel')
								: t('readPercent', percent)
						}
					>
						<div
							className="nvoos-cr-card-progress-fill"
							style={{ width: `${percent}%` }}
						/>
					</div>
				)}
				{progress && progress.completed && (
					<span className="nvoos-cr-card-completed">
						{t('completedLabel')}
					</span>
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
				onClick={() => onDelete(comic.id)}
				disabled={deleting}
				aria-label={t('deleteComic')}
				title={t('deleteComic')}
			>
				{deleting ? '…' : '×'}
			</button>
		</div>
	);
}

export function ComicLibrary({ onOpenComic }: ComicLibraryProps) {
	const [comics, setComics] = useState<ComicItem[]>([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [deleting, setDeleting] = useState<number | null>(null);
	const [progress, setProgress] = useState<Map<number, ProgressRecord>>(
		new Map()
	);

	const loadComics = useCallback(async () => {
		setLoading(true);
		setError(null);
		try {
			const data = await fetchComics();
			setComics(data.comics);
			setProgress(readAllLocalProgress());
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorLoad'));
		} finally {
			setLoading(false);
		}
	}, []);

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
				window.alert(err instanceof Error ? err.message : t('errorLoad'));
			} finally {
				setDeleting(null);
			}
		},
		[]
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
					{t('retry')}
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

	// Comics with recorded progress, most recently read first.
	const inProgress = comics
		.filter((comic) => progress.has(comic.id))
		.sort((a, b) => {
			const ta = progress.get(a.id)?.ts ?? 0;
			const tb = progress.get(b.id)?.ts ?? 0;
			return tb - ta;
		})
		.slice(0, 10);

	const renderGrid = (items: ComicItem[]) => (
		<div className="nvoos-cr-grid">
			{items.map((comic) => (
				<ComicCard
					key={comic.id}
					comic={comic}
					progress={progress.get(comic.id) ?? null}
					deleting={deleting === comic.id}
					onOpen={onOpenComic}
					onDelete={handleDelete}
				/>
			))}
		</div>
	);

	return (
		<div className="nvoos-cr-library">
			{inProgress.length > 0 && (
				<section className="nvoos-cr-library-section">
					<h2 className="nvoos-cr-library-heading">
						{t('continueReading')}
					</h2>
					{renderGrid(inProgress)}
				</section>
			)}

			<section className="nvoos-cr-library-section">
				<h2 className="nvoos-cr-library-heading">{t('allComics')}</h2>
				{renderGrid(comics)}
			</section>
		</div>
	);
}
