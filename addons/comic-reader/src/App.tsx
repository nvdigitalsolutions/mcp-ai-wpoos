/**
 * NV oOS Comic Reader — App Shell
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useCallback } from 'react';
import { ComicLibrary } from './components/ComicLibrary';
import { ComixReader } from './components/ComixReader';
import { ComicUploader } from './components/ComicUploader';
import type { ComicItem, ReaderConfig } from './api/comic-api';

interface AppProps {
	initialConfig: {
		comicId: number;
		mode: 'library' | 'reader';
		height: string;
		direction: 'ltr' | 'rtl';
	};
}

type ViewMode = 'library' | 'reader' | 'upload';

export function App({ initialConfig }: AppProps) {
	const [viewMode, setViewMode] = useState<ViewMode>(
		initialConfig.comicId ? 'reader' : initialConfig.mode
	);
	const [activeComic, setActiveComic] = useState<ComicItem | null>(null);
	const [direction, setDirection] = useState<'ltr' | 'rtl'>(initialConfig.direction);
	const [refreshKey, setRefreshKey] = useState(0);

	const handleOpenComic = useCallback((comic: ComicItem) => {
		setActiveComic(comic);
		setViewMode('reader');
	}, []);

	const handleBackToLibrary = useCallback(() => {
		setActiveComic(null);
		setViewMode('library');
		setRefreshKey((k) => k + 1);
	}, []);

	const handleUploadComplete = useCallback(() => {
		setViewMode('library');
		setRefreshKey((k) => k + 1);
	}, []);

	const handleToggleDirection = useCallback(() => {
		setDirection((d) => (d === 'ltr' ? 'rtl' : 'ltr'));
	}, []);

	const t = (key: string): string => {
		const i18n = window.NVOOS_COMIC_READER?.i18n || {};
		return i18n[key] || key;
	};

	return (
		<div
			className="nvoos-cr-app"
			style={initialConfig.height ? { minHeight: initialConfig.height } : undefined}
			dir={direction}
		>
			<header className="nvoos-cr-toolbar">
				<h1 className="nvoos-cr-title">{t('library')}</h1>
				<div className="nvoos-cr-toolbar-actions">
					{viewMode === 'reader' && (
						<button
							className="nvoos-cr-btn"
							onClick={handleBackToLibrary}
							aria-label={t('library')}
						>
							← {t('library')}
						</button>
					)}
					{viewMode === 'reader' && (
						<button
							className="nvoos-cr-btn"
							onClick={handleToggleDirection}
							aria-label={direction === 'ltr' ? t('readingRtl') : t('readingLtr')}
						>
							{direction === 'ltr' ? t('readingRtl') : t('readingLtr')}
						</button>
					)}
					<button
						className="nvoos-cr-btn nvoos-cr-btn-primary"
						onClick={() => setViewMode('upload')}
						aria-label={t('dropHint')}
					>
						+ Upload
					</button>
				</div>
			</header>

			<main className="nvoos-cr-main">
				{viewMode === 'library' && (
					<ComicLibrary
						key={refreshKey}
						onOpenComic={handleOpenComic}
					/>
				)}
				{viewMode === 'reader' && activeComic && (
					<ComixReader comic={activeComic} direction={direction} />
				)}
				{viewMode === 'upload' && (
					<ComicUploader
						onComplete={handleUploadComplete}
						onCancel={() => setViewMode('library')}
					/>
				)}
			</main>
		</div>
	);
}
