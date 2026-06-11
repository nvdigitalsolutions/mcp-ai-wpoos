/**
 * NV oOS Comic Reader — Main Reader Component
 *
 * Orchestrates archive extraction via Web Worker and renders the page viewer.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import type { ComicItem } from '../api/comic-api';
import { fetchComicFileUrl } from '../api/comic-api';
import { PageViewer } from './PageViewer';
import { useKeyboardNav } from '../hooks/useKeyboardNav';
import { useReaderState } from '../hooks/useReaderState';

interface PageData {
	index: number;
	name: string;
	url: string;
}

interface ComixReaderProps {
	comic: ComicItem;
	direction: 'ltr' | 'rtl';
}

// Build a Blob URL for the worker from our source.
// In a production build, esbuild inlines the worker.
function createWorkerFromSource(): Worker {
	const workerCode = `
		${/* The worker will be inlined at build time */ ''}
	`;
	const blob = new Blob([workerCode], { type: 'application/javascript' });
	return new Worker(URL.createObjectURL(blob));
}

export function ComixReader({ comic, direction }: ComixReaderProps) {
	const [pages, setPages] = useState<PageData[]>([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [extractProgress, setExtractProgress] = useState('');
	const workerRef = useRef<Worker | null>(null);
	const containerRef = useRef<HTMLDivElement>(null);

	const t = (key: string, ...args: (string | number)[]): string => {
		const i18n = window.NVOOS_COMIC_READER?.i18n || {};
		let msg = i18n[key] || key;
		args.forEach((arg, i) => {
			msg = msg.replace(`%${i + 1}$d`, String(arg));
		});
		return msg;
	};

	const {
		currentPage,
		totalPages,
		zoomLevel,
		fitMode,
		isDoublePage,
		isFullscreen,
		goToPage,
		nextPage,
		prevPage,
		zoomIn,
		zoomOut,
		setFitMode,
		toggleDoublePage,
		toggleFullscreen,
	} = useReaderState({ total: pages.length });

	// Keyboard navigation.
	useKeyboardNav(
		containerRef,
		{
			onNext: nextPage,
			onPrev: prevPage,
			onZoomIn: zoomIn,
			onZoomOut: zoomOut,
			direction,
		}
	);

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
					headers: { 'X-WP-Nonce': window.NVOOS_COMIC_READER?.nonce || '' },
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

					const { type, pages: extractedPages, total, message } = e.data;

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

				worker.postMessage({
					file: buffer,
					name: comic.filename,
				}, [buffer]);

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
	}, [comic.id, comic.filename, t]);

	// Save reading progress.
	useEffect(() => {
		if (currentPage > 0 && !loading) {
			try {
				localStorage.setItem(
					`nvoos_cr_progress_${comic.id}`,
					String(currentPage)
				);
			} catch {
				// localStorage unavailable.
			}
		}
	}, [currentPage, comic.id, loading]);

	// Restore reading progress.
	useEffect(() => {
		if (!loading && pages.length > 0) {
			try {
				const saved = localStorage.getItem(`nvoos_cr_progress_${comic.id}`);
				if (saved) {
					const page = parseInt(saved, 10);
					if (page > 0 && page <= pages.length) {
						goToPage(page);
					}
				}
			} catch {
				// localStorage unavailable.
			}
		}
	}, [loading, pages.length, comic.id, goToPage]);

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

	const leftPage = isDoublePage ? currentPage : currentPage;
	const rightPage = isDoublePage ? currentPage + 1 : null;

	return (
		<div
			ref={containerRef}
			className={`nvoos-cr-reader ${isFullscreen ? 'nvoos-cr-reader--fullscreen' : ''}`}
			tabIndex={0} // eslint-disable-line jsx-a11y/no-noninteractive-tabindex -- managed by useKeyboardNav hook
		>
			<PageViewer
				leftPage={
					leftPage <= pages.length
						? pages[leftPage - 1]
						: null
				}
				rightPage={
					rightPage && rightPage <= pages.length
						? pages[rightPage - 1]
						: null
				}
				zoomLevel={zoomLevel}
				fitMode={fitMode}
				direction={direction}
			/>

			<div className="nvoos-cr-controls">
				<div className="nvoos-cr-page-nav">
					<button
						className="nvoos-cr-btn"
						onClick={prevPage}
						disabled={currentPage <= 1}
						aria-label={t('previousPage')}
					>
						◀
					</button>
					<span className="nvoos-cr-page-indicator">
						{t('pageOf', currentPage, totalPages)}
					</span>
					<button
						className="nvoos-cr-btn"
						onClick={nextPage}
						disabled={currentPage >= totalPages}
						aria-label={t('nextPage')}
					>
						▶
					</button>
				</div>

				<div className="nvoos-cr-zoom-controls">
					<button
						className="nvoos-cr-btn"
						onClick={zoomOut}
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
						onClick={zoomIn}
						aria-label={t('zoomIn')}
						title={t('zoomIn')}
					>
						+
					</button>
					<button
						className={`nvoos-cr-btn ${fitMode === 'width' ? 'nvoos-cr-btn--active' : ''}`}
						onClick={() => setFitMode('width')}
						aria-label={t('fitWidth')}
						title={t('fitWidth')}
					>
						↔
					</button>
					<button
						className={`nvoos-cr-btn ${fitMode === 'height' ? 'nvoos-cr-btn--active' : ''}`}
						onClick={() => setFitMode('height')}
						aria-label={t('fitHeight')}
						title={t('fitHeight')}
					>
						↕
					</button>
				</div>

				<div className="nvoos-cr-mode-controls">
					<button
						className={`nvoos-cr-btn ${isDoublePage ? 'nvoos-cr-btn--active' : ''}`}
						onClick={toggleDoublePage}
						aria-label={isDoublePage ? t('singlePage') : t('doublePage')}
						title={isDoublePage ? t('singlePage') : t('doublePage')}
					>
						{isDoublePage ? '📄' : '📖'}
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
		</div>
	);
}
