// ---------------------------------------------------------------------------
// GalleryLightbox island — clean-room React component (D6), client:visible.
// Receives runtime-optimized image descriptors from the server-rendered page
// (/_image endpoint — build stays hermetic).
// ---------------------------------------------------------------------------

import { useState } from 'react';
import type { OptimizedImage } from '../lib/images';

export interface GalleryEntry {
	full: OptimizedImage;
	thumb: OptimizedImage;
}

interface Props {
	entries: GalleryEntry[];
	alt: string;
}

export default function GalleryLightbox({ entries, alt }: Props) {
	const [active, setActive] = useState(0);
	const [open, setOpen] = useState(false);

	if (entries.length === 0) {
		return null;
	}

	const current = entries[active];
	const hasMany = entries.length > 1;

	return (
		<div className="gallery">
			<button
				type="button"
				className="gallery__main"
				onClick={() => setOpen(true)}
				aria-label="Open image fullscreen"
			>
				<img
					src={current.full.src}
					srcset={current.full.srcset}
					sizes={current.full.sizes}
					width={current.full.width}
					height={current.full.height}
					alt={alt}
				/>
			</button>
			{hasMany && (
				<div className="gallery__thumbs" role="tablist" aria-label="Product images">
					{entries.map((entry, index) => (
						<button
							type="button"
							key={entry.thumb.src}
							className={index === active ? 'gallery__thumb is-active' : 'gallery__thumb'}
							onClick={() => setActive(index)}
							aria-label={`Show image ${index + 1} of ${entries.length}`}
						>
							<img
								src={entry.thumb.src}
								alt=""
								loading="lazy"
								width={entry.thumb.width}
								height={entry.thumb.height}
							/>
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
					<img src={current.full.src} alt={alt} />
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
