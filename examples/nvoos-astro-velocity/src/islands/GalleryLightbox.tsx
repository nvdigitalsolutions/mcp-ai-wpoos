// ---------------------------------------------------------------------------
// GalleryLightbox island — clean-room React component (D6), client:visible.
// Thumbnail strip + fullscreen overlay. Product images are already
// normalized to same-origin /media/* by the fetch edge.
// ---------------------------------------------------------------------------

import { useState } from 'react';

interface Props {
	images: string[];
	alt: string;
}

export default function GalleryLightbox({ images, alt }: Props) {
	const [active, setActive] = useState(0);
	const [open, setOpen] = useState(false);

	if (images.length === 0) {
		return null;
	}

	const current = images[active];
	const hasMany = images.length > 1;

	return (
		<div className="gallery">
			<button
				type="button"
				className="gallery__main"
				onClick={() => setOpen(true)}
				aria-label="Open image fullscreen"
			>
				<img src={current} alt={alt} width="600" height="750" />
			</button>
			{hasMany && (
				<div className="gallery__thumbs" role="tablist" aria-label="Product images">
					{images.map((src, index) => (
						<button
							type="button"
							key={src}
							className={index === active ? 'gallery__thumb is-active' : 'gallery__thumb'}
							onClick={() => setActive(index)}
							aria-label={`Show image ${index + 1} of ${images.length}`}
						>
							<img src={src} alt="" loading="lazy" width="80" height="100" />
						</button>
					))}
				</div>
			)}
			{open && (
				<div
					className="gallery__overlay"
					role="dialog"
					aria-modal="true"
					aria-label={alt}
					onClick={() => setOpen(false)}
				>
					<img src={current} alt={alt} />
					<button
						type="button"
						className="gallery__close"
						onClick={() => setOpen(false)}
						aria-label="Close"
					>
						Close
					</button>
				</div>
			)}
		</div>
	);
}
