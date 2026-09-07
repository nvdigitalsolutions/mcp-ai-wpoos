/**
 * NV oOS Comic Reader — Page Viewer
 *
 * Renders one or two comic pages in the viewport with zoom and fit-to-window
 * support. Supports the Komga-style scale types: fit-to-screen, fit-to-width,
 * fit-to-height, and original (free zoom).
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import type { CSSProperties } from 'react';
import type {
	PageTransition,
	ReaderBackground,
	ReadingDirection,
	ScaleType,
} from '../types/reader-prefs';
import { backgroundToColor } from '../types/reader-prefs';
import { t } from '../utils/i18n';

export interface PageData {
	index: number;
	name: string;
	url: string;
}

interface PageViewerProps {
	leftPage: PageData | null;
	rightPage: PageData | null;
	zoomLevel: number;
	scale: ScaleType;
	direction: ReadingDirection;
	background: ReaderBackground;
	transition: PageTransition;
	/** Key that changes on navigation so transitions can replay. */
	spreadKey: string;
	/** Reports natural image dimensions so spreads can honor landscape rules. */
	onPageLoad?: (index: number, width: number, height: number) => void;
}

export function PageViewer({
	leftPage,
	rightPage,
	zoomLevel,
	scale,
	direction,
	background,
	transition,
	spreadKey,
	onPageLoad,
}: PageViewerProps) {
	const isSpread = !!rightPage;

	if (!leftPage && !rightPage) {
		return (
			<div className="nvoos-cr-page-viewer nvoos-cr-page-viewer--empty">
				<p>{t('noPages')}</p>
			</div>
		);
	}

	const imageStyle = (): CSSProperties => {
		switch (scale) {
			case 'fit-width':
				return { maxWidth: '100%', height: 'auto' };
			case 'fit-height':
				return { maxHeight: '100%', width: 'auto' };
			case 'fit-screen':
				return { maxWidth: '100%', maxHeight: '100%', objectFit: 'contain' };
			case 'none':
			default:
				return {
					transform: `scale(${zoomLevel})`,
					transformOrigin: 'top center',
				};
		}
	};

	const handleLoad = (page: PageData) => {
		return (e: React.SyntheticEvent<HTMLImageElement>) => {
			const img = e.currentTarget;
			if (img.naturalWidth && img.naturalHeight && onPageLoad) {
				onPageLoad(page.index, img.naturalWidth, img.naturalHeight);
			}
		};
	};

	const transitionClass =
		transition === 'none' ? '' : `nvoos-cr-page-spread--${transition}`;

	return (
		<div
			className="nvoos-cr-page-viewer"
			style={{ background: backgroundToColor(background) }}
		>
			<div
				key={spreadKey}
				className={`nvoos-cr-page-spread ${
					isSpread ? 'nvoos-cr-page-spread--double' : ''
				} ${transitionClass}`}
				dir={direction}
			>
				{leftPage && (
					<div className="nvoos-cr-page" key={leftPage.index}>
						<img
							src={leftPage.url}
							alt={t('pageAlt', leftPage.index + 1)}
							style={imageStyle()}
							draggable={false}
							onLoad={handleLoad(leftPage)}
						/>
					</div>
				)}
				{rightPage && (
					<div className="nvoos-cr-page" key={rightPage.index}>
						<img
							src={rightPage.url}
							alt={t('pageAlt', rightPage.index + 1)}
							style={imageStyle()}
							draggable={false}
							onLoad={handleLoad(rightPage)}
						/>
					</div>
				)}
			</div>
		</div>
	);
}
