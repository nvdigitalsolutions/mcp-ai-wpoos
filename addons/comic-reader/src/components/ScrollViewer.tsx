/**
 * NV oOS Comic Reader — Scroll Viewer
 *
 * Vertical reading modes: "scroll" (stacked pages with gaps) and "webtoon"
 * (continuous vertical strip with no gaps). Tracks the visible page via
 * IntersectionObserver and reports scroll progress.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.3.0
 */

import { useEffect, useRef } from 'react';
import type { ReaderBackground, ReadingDirection } from '../types/reader-prefs';
import { backgroundToColor } from '../types/reader-prefs';
import { t } from '../utils/i18n';
import type { PageData } from './PageViewer';

interface ScrollViewerProps {
	pages: PageData[];
	direction: ReadingDirection;
	background: ReaderBackground;
	mode: 'scroll' | 'webtoon';
	onPageChange: (page: number, total: number) => void;
	onProgressChange: (percent: number) => void;
	initialPage: number;
	/** External jump request (e.g. thumbnails explorer). */
	jumpTo?: { page: number; nonce: number } | null;
}

export function ScrollViewer({
	pages,
	direction,
	background,
	mode,
	onPageChange,
	onProgressChange,
	initialPage,
	jumpTo,
}: ScrollViewerProps) {
	const containerRef = useRef<HTMLDivElement>(null);
	const observerRef = useRef<IntersectionObserver | null>(null);

	// Track the visible page + scroll progress.
	useEffect(() => {
		const container = containerRef.current;
		if (!container || typeof IntersectionObserver === 'undefined') {
			return;
		}

		const visible: Array<{ index: number; ratio: number }> = [];

		const observer = new IntersectionObserver(
			(entries) => {
				for (const entry of entries) {
					const index = Number(
						(entry.target as HTMLElement).dataset.pageIndex
					);
					const ratio = entry.intersectionRatio;
					const existing = visible.find((v) => v.index === index);
					if (entry.isIntersecting) {
						if (existing) {
							existing.ratio = ratio;
						} else {
							visible.push({ index, ratio });
						}
					} else if (existing) {
						visible.splice(visible.indexOf(existing), 1);
					}
				}

				// The most-visible page wins.
				if (visible.length > 0) {
					visible.sort((a, b) => b.ratio - a.ratio);
					onPageChange(visible[0].index + 1, pages.length);
				}
			},
			{ root: container, threshold: [0, 0.25, 0.5, 0.75, 1] }
		);

		container.querySelectorAll('[data-page-index]').forEach((el) => {
			observer.observe(el);
		});
		observerRef.current = observer;

		const handleScroll = () => {
			const max = container.scrollHeight - container.clientHeight;
			const percent =
				max > 0 ? Math.min(100, Math.max(0, (container.scrollTop / max) * 100)) : 0;
			onProgressChange(Math.round(percent));
		};
		container.addEventListener('scroll', handleScroll, { passive: true });

		return () => {
			observer.disconnect();
			observerRef.current = null;
			container.removeEventListener('scroll', handleScroll);
		};
	}, [pages.length, onPageChange, onProgressChange]);

	// Jump to the saved page on mount.
	useEffect(() => {
		const container = containerRef.current;
		if (!container || initialPage <= 1) {
			return;
		}
		const target = container.querySelector<HTMLElement>(
			`[data-page-index="${initialPage - 1}"]`
		);
		if (target) {
			target.scrollIntoView();
		}
		// The initial page jump is mount-only.
	}, []);

	// External jumps (thumbnails explorer).
	useEffect(() => {
		const container = containerRef.current;
		if (!container || !jumpTo) {
			return;
		}
		const target = container.querySelector<HTMLElement>(
			`[data-page-index="${jumpTo.page - 1}"]`
		);
		if (target) {
			target.scrollIntoView();
		}
	}, [jumpTo]);

	return (
		<div
			ref={containerRef}
			className={`nvoos-cr-scroll-viewer ${
				mode === 'webtoon' ? 'nvoos-cr-scroll-viewer--webtoon' : ''
			}`}
			style={{ background: backgroundToColor(background) }}
			dir={direction}
		>
			{pages.map((page) => (
				<div
					key={page.index}
					className="nvoos-cr-scroll-page"
					data-page-index={page.index}
				>
					<img
						src={page.url}
						alt={t('pageAlt', page.index + 1)}
						loading="lazy"
						draggable={false}
					/>
				</div>
			))}
		</div>
	);
}
