/**
 * NV oOS Comic Reader — Page Viewer
 *
 * Renders one or two comic pages in the viewport with zoom and fit-to-window support.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

interface PageData {
	index: number;
	name: string;
	url: string;
}

interface PageViewerProps {
	leftPage: PageData | null;
	rightPage: PageData | null;
	zoomLevel: number;
	fitMode: 'none' | 'width' | 'height';
	direction: 'ltr' | 'rtl';
}

export function PageViewer({
	leftPage,
	rightPage,
	zoomLevel,
	fitMode,
	direction,
}: PageViewerProps) {
	const isSpread = !!rightPage;

	if (!leftPage && !rightPage) {
		return (
			<div className="nvoos-cr-page-viewer nvoos-cr-page-viewer--empty">
				<p>No pages to display.</p>
			</div>
		);
	}

	const imageStyle: React.CSSProperties = {
		transform: `scale(${zoomLevel})`,
		transformOrigin: 'top center',
		maxWidth: fitMode === 'width' ? '100%' : undefined,
		maxHeight: fitMode === 'height' ? '100%' : undefined,
		objectFit: fitMode !== 'none' ? 'contain' : undefined,
		display: 'block',
	};

	return (
		<div className="nvoos-cr-page-viewer">
			<div
				className={`nvoos-cr-page-spread ${
					isSpread ? 'nvoos-cr-page-spread--double' : ''
				}`}
				dir={direction}
			>
				{leftPage && (
					<div className="nvoos-cr-page" key={leftPage.index}>
						<img
							src={leftPage.url}
							alt={`Page ${leftPage.index + 1}`}
							style={imageStyle}
							draggable={false}
						/>
					</div>
				)}
				{rightPage && (
					<div className="nvoos-cr-page" key={rightPage.index}>
						<img
							src={rightPage.url}
							alt={`Page ${rightPage.index + 1}`}
							style={imageStyle}
							draggable={false}
						/>
					</div>
				)}
			</div>
		</div>
	);
}
