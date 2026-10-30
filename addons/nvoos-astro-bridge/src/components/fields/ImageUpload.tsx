/**
 * Image upload field — native file input + REST upload.
 *
 * Runs identically in wp-admin and on the standalone /manage surface: the
 * file POSTs to the CMS media endpoint (multipart + X-WP-Nonce), previews
 * through the host's media base (same-origin proxy on the storefront,
 * absolute attachment URL in wp-admin).
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';
import {
	fetchMedia,
	uploadMedia,
	mediaUrl,
	type MediaUploadResult,
} from '../../hooks/useCollection';

interface ImageUploadProps {
	label: string;
	/** Attachment ID or empty string / '' when no image. */
	value: number | string | null;
	/** Validation message from the form schema (renders below the field). */
	error?: string;
	onChange: (attachmentId: number | null) => void;
}

export function ImageUpload({ label, value, error, onChange }: ImageUploadProps) {
	const mediaId = typeof value === 'number' && value > 0 ? value : null;

	const [media, setMedia] = useState<MediaUploadResult | null>(null);
	const [uploadError, setUploadError] = useState<string | null>(null);
	const [busy, setBusy] = useState(false);
	const inputRef = useRef<HTMLInputElement | null>(null);

	// Resolve the preview record for an already-attached image.
	useEffect(() => {
		let cancelled = false;
		if (!mediaId) {
			setMedia(null);
			return;
		}
		fetchMedia(mediaId)
			.then((record) => {
				if (!cancelled) {
					setMedia(record);
				}
			})
			.catch(() => {
				// Preview unavailable — the field still edits the ID.
				if (!cancelled) {
					setMedia(null);
				}
			});
		return () => {
			cancelled = true;
		};
	}, [mediaId]);

	const handleFile = async (file: File | undefined): Promise<void> => {
		if (!file) {
			return;
		}
		setBusy(true);
		setUploadError(null);
		try {
			const record = await uploadMedia(file);
			setMedia(record);
			onChange(record.id);
		} catch (err) {
			setUploadError(err instanceof Error ? err.message : 'Upload failed.');
		} finally {
			setBusy(false);
		}
	};

	return (
		<div style={{ marginBottom: 16 }}>
			<label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>{label}</label>
			{mediaId && media ? (
				<div style={{ marginBottom: 8 }}>
					<img
						src={mediaUrl(media)}
						alt=""
						style={{
							maxWidth: 200,
							maxHeight: 200,
							display: 'block',
							marginBottom: 8,
							border: '1px solid #ddd',
							borderRadius: 4,
						}}
					/>
					<div style={{ display: 'flex', gap: 8 }}>
						<Button
							variant="secondary"
							isBusy={busy}
							onClick={() => inputRef.current?.click()}
						>
							Replace
						</Button>
						<Button variant="tertiary" isDestructive onClick={() => onChange(null)}>
							Remove
						</Button>
					</div>
				</div>
			) : (
				<Button
					variant="secondary"
					isBusy={busy}
					onClick={() => inputRef.current?.click()}
				>
					{mediaId ? 'Replace image' : 'Select image'}
				</Button>
			)}
			{uploadError && <p style={{ color: '#b32d2e', margin: '8px 0 0' }}>{uploadError}</p>}
			{error && <p style={{ color: '#b32d2e', margin: '8px 0 0' }}>{error}</p>}
			{/* Hidden native input — works everywhere, no media-modal dependency. */}
			<input
				ref={inputRef}
				type="file"
				accept="image/*"
				style={{ display: 'none' }}
				onChange={(event) => void handleFile(event.target.files?.[0])}
			/>
		</div>
	);
}
