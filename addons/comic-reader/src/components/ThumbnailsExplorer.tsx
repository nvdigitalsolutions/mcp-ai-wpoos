/**
 * NV oOS Comic Reader — Thumbnails Explorer
 *
 * Overlay grid of every page for quick navigation, with the current page
 * highlighted. Mirrors Komga's thumbnails explorer.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.3.0
 */

import { useEffect, useRef } from 'react';
import { t } from '../utils/i18n';
import type { PageData } from './PageViewer';

interface ThumbnailsExplorerProps {
	pages: PageData[];
	currentPage: number;
	onSelect: (page: number) => void;
	onClose: () => void;
}

export function ThumbnailsExplorer({
	pages,
	currentPage,
	onSelect,
	onClose,
}: ThumbnailsExplorerProps) {
	const dialogRef = useRef<HTMLDivElement>(null);

	// Focus management: move focus into the dialog on open, restore on close.
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

	return (
		<div className="nvoos-cr-overlay" role="dialog" aria-modal="true" aria-label={t('thumbnails')}>
			<div className="nvoos-cr-overlay-header">
				<h2 className="nvoos-cr-overlay-title">{t('thumbnails')}</h2>
				<button className="nvoos-cr-btn" onClick={onClose} aria-label={t('close')}>
					×
				</button>
			</div>
			<div className="nvoos-cr-thumbnails" ref={dialogRef} tabIndex={-1}>
				{pages.map((page) => (
					<button
						key={page.index}
						className={`nvoos-cr-thumb ${
							page.index + 1 === currentPage
								? 'nvoos-cr-thumb--active'
								: ''
						}`}
						onClick={() => {
							onSelect(page.index + 1);
							onClose();
						}}
						aria-label={t('pageProgress', page.index + 1)}
						aria-current={
							page.index + 1 === currentPage ? 'true' : undefined
						}
					>
						<img
							src={page.url}
							alt={t('pageAlt', page.index + 1)}
							loading="lazy"
						/>
						<span className="nvoos-cr-thumb-index">
							{page.index + 1}
						</span>
					</button>
				))}
			</div>
		</div>
	);
}
