/**
 * NV oOS Comic Reader — Comic Library Component
 *
 * Library view with a continue-reading shelf, search, sort, series filters,
 * pagination, per-comic metadata editing, and collections — mirroring
 * Komga's library organization on WordPress primitives.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import {
	fetchComics,
	fetchSeries,
	fetchCollections,
	deleteComic,
	formatFileSize,
	type ComicItem,
	type ComicSeries,
	type ComicCollection,
} from '../api/comic-api';
import { fetchProgressMap } from '../api/comic-api';
import {
	readAllLocalProgress,
	progressPercent,
	type ProgressRecord,
} from '../types/progress';
import { MetadataEditor } from './MetadataEditor';
import { CollectionPicker } from './CollectionPicker';
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
	onEdit: (comic: ComicItem) => void;
	onCollect: (comic: ComicItem) => void;
}

function ComicCard({
	comic,
	progress,
	deleting,
	onOpen,
	onDelete,
	onEdit,
	onCollect,
}: ComicCardProps) {
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
					{comic.series.length > 0 && (
						<>
							{' '}
							&middot; <span className="nvoos-cr-card-series">{comic.series[0]}</span>
						</>
					)}
				</p>
			</div>
			<button
				className="nvoos-cr-card-edit"
				onClick={() => onEdit(comic)}
				aria-label={t('editMetadata')}
				title={t('editMetadata')}
			>
				✎
			</button>
			<button
				className="nvoos-cr-card-collect"
				onClick={() => onCollect(comic)}
				aria-label={t('addToCollection')}
				title={t('addToCollection')}
			>
				＋
			</button>
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

const PER_PAGE = 20;

export function ComicLibrary({ onOpenComic }: ComicLibraryProps) {
	const [comics, setComics] = useState<ComicItem[]>([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [deleting, setDeleting] = useState<number | null>(null);
	const [progress, setProgress] = useState<Map<number, ProgressRecord>>(
		new Map()
	);
	const [seriesFacets, setSeriesFacets] = useState<ComicSeries[]>([]);
	const [collections, setCollections] = useState<ComicCollection[]>([]);
	const [page, setPage] = useState(1);
	const [totalPages, setTotalPages] = useState(1);
	const [search, setSearch] = useState('');
	const [seriesFilter, setSeriesFilter] = useState('');
	const [orderby, setOrderby] = useState<'date' | 'title'>('date');
	const [editingComic, setEditingComic] = useState<ComicItem | null>(null);
	const [collectingComic, setCollectingComic] = useState<ComicItem | null>(
		null
	);
	const searchTimer = useRef<number | null>(null);

	const loadComics = useCallback(async () => {
		setLoading(true);
		setError(null);
		try {
			const data = await fetchComics(
				page,
				PER_PAGE,
				search,
				seriesFilter,
				orderby
			);
			setComics(data.comics);
			setTotalPages(Math.max(1, data.total_pages));

			// Merge server progress over local records.
			const local = readAllLocalProgress();
			try {
				const server = await fetchProgressMap();
				for (const [id, record] of Object.entries(server)) {
					const numericId = Number(id);
					const localRecord = local.get(numericId);
					if (!localRecord || record.ts >= localRecord.ts) {
						local.set(numericId, record);
					}
				}
			} catch {
				// Server unavailable — local only.
			}
			setProgress(local);
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorLoad'));
		} finally {
			setLoading(false);
		}
	}, [page, search, seriesFilter, orderby]);

	useEffect(() => {
		loadComics();
	}, [loadComics]);

	// Load series facets and collections once.
	useEffect(() => {
		fetchSeries()
			.then(setSeriesFacets)
			.catch(() => setSeriesFacets([]));
		fetchCollections()
			.then(setCollections)
			.catch(() => setCollections([]));
	}, []);

	// Debounced search input.
	const handleSearchChange = useCallback((value: string) => {
		setSearch(value);
		if (searchTimer.current) {
			window.clearTimeout(searchTimer.current);
		}
		searchTimer.current = window.setTimeout(() => {
			setPage(1);
		}, 0);
	}, []);

	useEffect(() => {
		return () => {
			if (searchTimer.current) {
				window.clearTimeout(searchTimer.current);
			}
		};
	}, []);

	const handleDelete = useCallback(async (id: number) => {
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
	}, []);

	const handleSaved = useCallback((updated: ComicItem) => {
		setComics((prev) =>
			prev.map((c) => (c.id === updated.id ? updated : c))
		);
	}, []);

	if (loading && comics.length === 0) {
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

	if (comics.length === 0 && page === 1 && !search && !seriesFilter) {
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
					onEdit={setEditingComic}
					onCollect={setCollectingComic}
				/>
			))}
		</div>
	);

	return (
		<div className="nvoos-cr-library">
			<div className="nvoos-cr-library-toolbar">
				<input
					type="search"
					className="nvoos-cr-search"
					placeholder={t('searchPlaceholder')}
					value={search}
					onChange={(e) => handleSearchChange(e.target.value)}
					aria-label={t('searchPlaceholder')}
				/>
				<label className="nvoos-cr-sort">
					<span className="nvoos-cr-sort-label">{t('sortLabel')}</span>
					<select
						className="nvoos-cr-settings-select"
						value={orderby}
						onChange={(e) => {
							setOrderby(e.target.value as 'date' | 'title');
							setPage(1);
						}}
					>
						<option value="date">{t('sortDate')}</option>
						<option value="title">{t('sortTitle')}</option>
					</select>
				</label>
			</div>

			{seriesFacets.length > 0 && (
				<div className="nvoos-cr-series-filters" role="group" aria-label={t('filterSeries')}>
					<button
						className={`nvoos-cr-chip ${
							seriesFilter === '' ? 'nvoos-cr-chip--active' : ''
						}`}
						onClick={() => {
							setSeriesFilter('');
							setPage(1);
						}}
					>
						{t('allSeries')}
					</button>
					{seriesFacets.map((facet) => (
						<button
							key={facet.id}
							className={`nvoos-cr-chip ${
								seriesFilter === facet.name
									? 'nvoos-cr-chip--active'
									: ''
							}`}
							onClick={() => {
								setSeriesFilter(facet.name);
								setPage(1);
							}}
						>
							{facet.name} ({facet.count})
						</button>
					))}
				</div>
			)}

			{comics.length === 0 ? (
				<div className="nvoos-cr-empty">
					<p>{t('noResults')}</p>
				</div>
			) : (
				<>
					{page === 1 && inProgress.length > 0 && (
						<section className="nvoos-cr-library-section">
							<h2 className="nvoos-cr-library-heading">
								{t('continueReading')}
							</h2>
							{renderGrid(inProgress)}
						</section>
					)}

					<section className="nvoos-cr-library-section">
						<h2 className="nvoos-cr-library-heading">
							{t('allComics')}
						</h2>
						{renderGrid(comics)}
					</section>

					{totalPages > 1 && (
						<div className="nvoos-cr-pagination">
							<button
								className="nvoos-cr-btn"
								onClick={() => setPage((p) => Math.max(1, p - 1))}
								disabled={page <= 1}
							>
								◀
							</button>
							<span className="nvoos-cr-page-indicator">
								{t('pageOf', page, totalPages)}
							</span>
							<button
								className="nvoos-cr-btn"
								onClick={() =>
									setPage((p) => Math.min(totalPages, p + 1))
								}
								disabled={page >= totalPages}
							>
								▶
							</button>
						</div>
					)}
				</>
			)}

			{editingComic && (
				<MetadataEditor
					comic={editingComic}
					onClose={() => setEditingComic(null)}
					onSaved={handleSaved}
				/>
			)}

			{collectingComic && (
				<CollectionPicker
					comicId={collectingComic.id}
					collections={collections}
					onClose={() => setCollectingComic(null)}
					onChanged={setCollections}
				/>
			)}
		</div>
	);
}
