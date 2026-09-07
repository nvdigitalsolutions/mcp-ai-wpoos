/**
 * NV oOS Comic Reader — Main Reader Component
 *
 * Orchestrates archive extraction via Web Worker and renders the page viewer.
 * Implements Komga's webreader feature set: paged / vertical / webtoon
 * reading modes, four scale types, background colors, double-page rules
 * (first/last single, landscape single), page transitions, thumbnails
 * explorer, help dialog, persisted settings, and touch gestures.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import type { ComicItem } from '../api/comic-api';
import { fetchComicFileUrl } from '../api/comic-api';
import { PageViewer } from './PageViewer';
import type { PageData } from './PageViewer';
import { ScrollViewer } from './ScrollViewer';
import { ThumbnailsExplorer } from './ThumbnailsExplorer';
import { HelpDialog } from './HelpDialog';
import { ReaderSettings } from './ReaderSettings';
import { useKeyboardNav } from '../hooks/useKeyboardNav';
import { useReaderState } from '../hooks/useReaderState';
import { useTouchGestures } from '../hooks/useTouchGestures';
import {
	loadPrefs,
	savePrefs,
	backgroundToColor,
} from '../types/reader-prefs';
import type { ReaderPrefs, ReadingDirection } from '../types/reader-prefs';
import { saveLocalProgress, readLocalProgress } from '../types/progress';
import { t } from '../utils/i18n';

interface ComixReaderProps {
	comic: ComicItem;
	initialDirection: ReadingDirection;
}

interface PageDims {
	width: number;
	height: number;
}

interface Spread {
	left: number;
	right: number | null;
}

/** Compute the visible spread honoring Komga's double-page rules. */
function computeSpread(
	currentPage: number,
	totalPages: number,
	doublePage: boolean,
	direction: ReadingDirection,
	dims: Record<number, PageDims>
): Spread {
	const single = (page: number): Spread => ({ left: page, right: null });

	if (!doublePage || totalPages < 2) {
		return single(currentPage);
	}
	// First and last page always render single.
	if (currentPage === 1) {
		return single(1);
	}
	if (currentPage >= totalPages) {
		return single(totalPages);
	}

	const isRtl = direction === 'rtl';
	const first = isRtl ? currentPage + 1 : currentPage;
	const second = isRtl ? currentPage : currentPage + 1;

	// Landscape pages (width > height) render single.
	const isLandscape = (page: number): boolean => {
		const dim = dims[page];
		return !!dim && dim.width > dim.height;
	};
	if (isLandscape(first) || isLandscape(second)) {
		return single(currentPage);
	}

	return { left: first, right: second };
}

export function ComixReader({ comic, initialDirection }: ComixReaderProps) {
	const [pages, setPages] = useState<PageData[]>([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [extractProgress, setExtractProgress] = useState('');
	const [prefs, setPrefs] = useState<ReaderPrefs>(() => ({
		...loadPrefs(comic.id),
		direction: initialDirection,
	}));
	const [pageDims, setPageDims] = useState<Record<number, PageDims>>({});
	const [showSettings, setShowSettings] = useState(false);
	const [showThumbnails, setShowThumbnails] = useState(false);
	const [showHelp, setShowHelp] = useState(false);
	const [uiVisible, setUiVisible] = useState(true);
	const [scrollPage, setScrollPage] = useState(1);
	const [scrollJump, setScrollJump] = useState<{ page: number; nonce: number } | null>(null);

	const workerRef = useRef<Worker | null>(null);
	const containerRef = useRef<HTMLDivElement>(null);
	const totalPages = Math.max(pages.length, 1);

	const {
		currentPage,
		zoomLevel,
		scale,
		isFullscreen,
		goToPage,
		nextPage,
		prevPage,
		zoomIn,
		zoomOut,
		setScale,
		setDoublePage,
		toggleFullscreen,
	} = useReaderState({
		total: totalPages,
		initialScale: prefs.scale,
		initialDoublePage: prefs.doublePage,
	});

	const activePage = prefs.readingMode === 'paged' ? currentPage : scrollPage;

	// Keep reader state in sync with settings-dialog changes.
	useEffect(() => {
		setScale(prefs.scale);
	}, [prefs.scale, setScale]);
	useEffect(() => {
		setDoublePage(prefs.doublePage);
	}, [prefs.doublePage, setDoublePage]);

	// Persist prefs on change (per-comic override).
	useEffect(() => {
		savePrefs(prefs, comic.id);
	}, [prefs, comic.id]);

	const updatePrefs = useCallback((patch: Partial<ReaderPrefs>) => {
		setPrefs((p) => ({ ...p, ...patch }));
	}, []);

	const spread = useMemo(
		() =>
			computeSpread(
				currentPage,
				totalPages,
				prefs.readingMode === 'paged' && prefs.doublePage,
				prefs.direction,
				pageDims
			),
		[currentPage, totalPages, prefs.doublePage, prefs.readingMode, prefs.direction, pageDims]
	);

	const handlePageLoad = useCallback((index: number, width: number, height: number) => {
		setPageDims((dims) => {
			const existing = dims[index];
			if (existing && existing.width === width && existing.height === height) {
				return dims;
			}
			return { ...dims, [index]: { width, height } };
		});
	}, []);

	// Navigation steps by the number of pages actually shown.
	const step = spread.right ? 2 : 1;
	const navNext = useCallback(() => nextPage(step), [nextPage, step]);
	const navPrev = useCallback(() => prevPage(step), [prevPage, step]);

	const handleZoomIn = useCallback(() => {
		updatePrefs({ scale: 'none' });
		zoomIn();
	}, [zoomIn, updatePrefs]);
	const handleZoomOut = useCallback(() => {
		updatePrefs({ scale: 'none' });
		zoomOut();
	}, [zoomOut, updatePrefs]);

	// Load and extract the comic archive.
	useEffect(() => {
		let cancelled = false;
		const cleanupUrls: string[] = [];

		async function loadArchive() {
			setLoading(true);
			setError(null);
			setExtractProgress(t('extracting'));

			try {
				// Fetch the raw file.
				const fileUrl = await fetchComicFileUrl(comic.id);
				const response = await fetch(fileUrl, {
					headers: {
						'X-WP-Nonce': window.NVOOS_COMIC_READER?.nonce || '',
					},
				});

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}`);
				}

				const buffer = await response.arrayBuffer();

				if (cancelled) return;

				// Use a Web Worker for extraction.
				const worker = new Worker(
					new URL('../api/archive-worker.ts', import.meta.url),
					{ type: 'module' }
				);
				workerRef.current = worker;

				worker.onmessage = (e: MessageEvent) => {
					if (cancelled) return;

					const { type, pages: extractedPages, message } = e.data;

					if (type === 'success') {
						const pageData: PageData[] = extractedPages;
						pageData.forEach((p) => cleanupUrls.push(p.url));
						setPages(pageData);
						setLoading(false);
						setExtractProgress('');
					} else if (type === 'error') {
						setError(message);
						setLoading(false);
						setExtractProgress('');
					}
				};

				worker.onerror = () => {
					if (!cancelled) {
						setError(t('errorLoad'));
						setLoading(false);
						setExtractProgress('');
					}
				};

				worker.postMessage(
					{
						file: buffer,
						name: comic.filename,
					},
					[buffer]
				);
			} catch (err) {
				if (!cancelled) {
					setError(err instanceof Error ? err.message : t('errorLoad'));
					setLoading(false);
					setExtractProgress('');
				}
			}
		}

		loadArchive();

		return () => {
			cancelled = true;
			cleanupUrls.forEach((url) => URL.revokeObjectURL(url));
			if (workerRef.current) {
				workerRef.current.terminate();
			}
		};
		// Extraction is keyed by comic identity; t() is a stable helper.
	}, [comic.id, comic.filename]);

	// Save reading progress (localStorage; server sync lands in 0.4.0).
	useEffect(() => {
		if (loading || pages.length === 0 || activePage <= 0) return;
		const completed = activePage >= pages.length;
		saveLocalProgress(comic.id, {
			page: activePage,
			total: pages.length,
			completed,
			ts: Date.now(),
		});
	}, [activePage, pages.length, loading, comic.id]);

	// Restore reading progress.
	useEffect(() => {
		if (loading || pages.length === 0) return;
		const saved = readLocalProgress(comic.id);
		if (!saved || saved.page <= 1) return;

		if (prefs.readingMode === 'paged') {
			goToPage(Math.min(saved.page, pages.length));
		} else {
			setScrollPage(Math.min(saved.page, pages.length));
		}
		// Restore runs once per comic open.
	}, [loading, pages.length, comic.id]);

	// Keyboard navigation. Arrow keys only capture page turns in paged mode;
	// in scroll modes they must keep native scrolling behavior.
	useKeyboardNav(containerRef, {
		onNext: navNext,
		onPrev: navPrev,
		onZoomIn: handleZoomIn,
		onZoomOut: handleZoomOut,
		onFitWidth: () => updatePrefs({ scale: 'fit-width' }),
		onFitHeight: () => updatePrefs({ scale: 'fit-height' }),
		onFullscreen: toggleFullscreen,
		onToggleDoublePage: () =>
			updatePrefs({ doublePage: !prefs.doublePage }),
		onToggleDirection: () =>
			updatePrefs({
				direction: prefs.direction === 'ltr' ? 'rtl' : 'ltr',
			}),
		onThumbnails: () => setShowThumbnails(true),
		onHelp: () => setShowHelp(true),
		direction: prefs.direction,
		captureArrows: prefs.readingMode === 'paged',
	});

	// Touch gestures (paged mode only; scroll modes handle their own scroll).
	useTouchGestures(containerRef, {
		onNext: navNext,
		onPrev: navPrev,
		onZoomIn: handleZoomIn,
		onZoomOut: handleZoomOut,
		onTapCenter: () => setUiVisible((v) => !v),
		enabled: prefs.gestures && prefs.readingMode === 'paged',
		direction: prefs.direction,
	});

	if (loading) {
		return (
			<div className="nvoos-cr-loading" role="status">
				<div className="nvoos-cr-spinner" />
				<span>{extractProgress || t('loading')}</span>
			</div>
		);
	}

	if (error) {
		return (
			<div className="nvoos-cr-error" role="alert">
				<p>{error}</p>
			</div>
		);
	}

	const leftPageData = spread.left <= pages.length ? pages[spread.left - 1] : null;
	const rightPageData = spread.right && spread.right <= pages.length ? pages[spread.right - 1] : null;

	const renderViewer = () => {
		if (prefs.readingMode === 'paged') {
			return (
				<PageViewer
					leftPage={leftPageData}
					rightPage={rightPageData}
					zoomLevel={zoomLevel}
					scale={scale}
					direction={prefs.direction}
					background={prefs.background}
					transition={prefs.transition}
					spreadKey={`${spread.left}-${spread.right ?? 's'}-${prefs.direction}`}
					onPageLoad={handlePageLoad}
				/>
			);
		}
		return (
			<ScrollViewer
				pages={pages}
				direction={prefs.direction}
				background={prefs.background}
				mode={prefs.readingMode}
				onPageChange={(page) => setScrollPage(page)}
				onProgressChange={() => {
					// Scroll progress is captured via page changes for progress storage.
				}}
				initialPage={scrollPage}
				jumpTo={scrollJump}
			/>
		);
	};

	const isPaged = prefs.readingMode === 'paged';

	return (
		<div
			ref={containerRef}
			className={`nvoos-cr-reader ${
				isFullscreen ? 'nvoos-cr-reader--fullscreen' : ''
			} ${uiVisible ? '' : 'nvoos-cr-reader--hide-ui'}`}
			style={{
				background: backgroundToColor(prefs.background),
			}}
			tabIndex={0} // eslint-disable-line jsx-a11y/no-noninteractive-tabindex -- managed by useKeyboardNav hook
		>
			{renderViewer()}

			<div className="nvoos-cr-controls">
				<div className="nvoos-cr-page-nav">
					{isPaged && (
						<button
							className="nvoos-cr-btn"
							onClick={navPrev}
							disabled={currentPage <= 1}
							aria-label={t('previousPage')}
						>
							◀
						</button>
					)}
					<span className="nvoos-cr-page-indicator">
						{t('pageOf', activePage, totalPages)}
					</span>
					{isPaged && (
						<button
							className="nvoos-cr-btn"
							onClick={navNext}
							disabled={currentPage >= totalPages}
							aria-label={t('nextPage')}
						>
							▶
						</button>
					)}
				</div>

				{isPaged && (
					<div className="nvoos-cr-zoom-controls">
						<button
							className="nvoos-cr-btn"
							onClick={handleZoomOut}
							aria-label={t('zoomOut')}
							title={t('zoomOut')}
						>
							−
						</button>
						<span className="nvoos-cr-zoom-label">
							{Math.round(zoomLevel * 100)}%
						</span>
						<button
							className="nvoos-cr-btn"
							onClick={handleZoomIn}
							aria-label={t('zoomIn')}
							title={t('zoomIn')}
						>
							+
						</button>
						<button
							className={`nvoos-cr-btn ${
								scale === 'fit-width' ? 'nvoos-cr-btn--active' : ''
							}`}
							onClick={() => updatePrefs({ scale: 'fit-width' })}
							aria-label={t('fitWidth')}
							title={t('fitWidth')}
						>
							↔
						</button>
						<button
							className={`nvoos-cr-btn ${
								scale === 'fit-height' ? 'nvoos-cr-btn--active' : ''
							}`}
							onClick={() => updatePrefs({ scale: 'fit-height' })}
							aria-label={t('fitHeight')}
							title={t('fitHeight')}
						>
							↕
						</button>
						<button
							className={`nvoos-cr-btn ${
								scale === 'fit-screen' ? 'nvoos-cr-btn--active' : ''
							}`}
							onClick={() => updatePrefs({ scale: 'fit-screen' })}
							aria-label={t('fitScreen')}
							title={t('fitScreen')}
						>
							⛶
						</button>
					</div>
				)}

				<div className="nvoos-cr-mode-controls">
					{isPaged && (
						<button
							className={`nvoos-cr-btn ${
								prefs.doublePage ? 'nvoos-cr-btn--active' : ''
							}`}
							onClick={() =>
								updatePrefs({ doublePage: !prefs.doublePage })
							}
							aria-label={
								prefs.doublePage ? t('singlePage') : t('doublePage')
							}
							title={
								prefs.doublePage ? t('singlePage') : t('doublePage')
							}
						>
							{prefs.doublePage ? '📄' : '📖'}
						</button>
					)}
					<button
						className="nvoos-cr-btn"
						onClick={() =>
							updatePrefs({
								direction:
									prefs.direction === 'ltr' ? 'rtl' : 'ltr',
							})
						}
						aria-label={
							prefs.direction === 'ltr'
								? t('readingRtl')
								: t('readingLtr')
						}
						title={
							prefs.direction === 'ltr'
								? t('readingRtl')
								: t('readingLtr')
						}
					>
						{prefs.direction === 'ltr' ? '→' : '←'}
					</button>
					<button
						className="nvoos-cr-btn"
						onClick={() => setShowThumbnails(true)}
						aria-label={t('thumbnails')}
						title={t('thumbnails')}
					>
						⊞
					</button>
					<button
						className="nvoos-cr-btn"
						onClick={() => setShowSettings(true)}
						aria-label={t('settings')}
						title={t('settings')}
					>
						⚙
					</button>
					<button
						className="nvoos-cr-btn"
						onClick={() => setShowHelp(true)}
						aria-label={t('help')}
						title={t('help')}
					>
						?
					</button>
					<button
						className="nvoos-cr-btn"
						onClick={toggleFullscreen}
						aria-label={isFullscreen ? t('exitFullscreen') : t('fullscreen')}
						title={isFullscreen ? t('exitFullscreen') : t('fullscreen')}
					>
						{isFullscreen ? '↙' : '↗'}
					</button>
				</div>
			</div>

			{showSettings && (
				<ReaderSettings
					prefs={prefs}
					onChange={setPrefs}
					onClose={() => setShowSettings(false)}
				/>
			)}
			{showThumbnails && (
				<ThumbnailsExplorer
					pages={pages}
					currentPage={activePage}
					onSelect={(page) => {
						if (prefs.readingMode === 'paged') {
							goToPage(page);
						} else {
							setScrollPage(page);
							setScrollJump((j) => ({
								page,
								nonce: (j?.nonce ?? 0) + 1,
							}));
						}
					}}
					onClose={() => setShowThumbnails(false)}
				/>
			)}
			{showHelp && <HelpDialog onClose={() => setShowHelp(false)} />}
		</div>
	);
}
