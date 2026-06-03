/**
 * NV oOS Comic Reader — Comic Uploader
 *
 * Drag-and-drop upload interface for comic files.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useCallback, useRef } from 'react';
import { uploadComic } from '../api/comic-api';

interface ComicUploaderProps {
	onComplete: () => void;
	onCancel: () => void;
}

const SUPPORTED_EXTENSIONS = ['.cbr', '.cbz', '.cb7', '.cbt'];

function isValidFile(file: File): boolean {
	const name = file.name.toLowerCase();
	return SUPPORTED_EXTENSIONS.some((ext) => name.endsWith(ext));
}

export function ComicUploader({ onComplete, onCancel }: ComicUploaderProps) {
	const [dragging, setDragging] = useState(false);
	const [uploading, setUploading] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const [progress, setProgress] = useState('');
	const inputRef = useRef<HTMLInputElement>(null);

	const t = (key: string): string =>
		window.NVOOS_COMIC_READER?.i18n?.[key] || key;

	const handleUpload = useCallback(
		async (file: File) => {
			if (!isValidFile(file)) {
				setError(t('unsupported'));
				return;
			}

			setUploading(true);
			setError(null);
			setProgress(t('uploading'));

			try {
				await uploadComic(file);
				onComplete();
			} catch (err) {
				setError(err instanceof Error ? err.message : t('errorLoad'));
			} finally {
				setUploading(false);
				setProgress('');
			}
		},
		[onComplete, t]
	);

	const handleDrop = useCallback(
		(e: React.DragEvent) => {
			e.preventDefault();
			setDragging(false);

			const file = e.dataTransfer.files[0];
			if (file) handleUpload(file);
		},
		[handleUpload]
	);

	const handleDragOver = useCallback((e: React.DragEvent) => {
		e.preventDefault();
		setDragging(true);
	}, []);

	const handleDragLeave = useCallback((e: React.DragEvent) => {
		e.preventDefault();
		setDragging(false);
	}, []);

	const handleFileSelect = useCallback(
		(e: React.ChangeEvent<HTMLInputElement>) => {
			const file = e.target.files?.[0];
			if (file) handleUpload(file);
		},
		[handleUpload]
	);

	return (
		<div className="nvoos-cr-uploader">
			<div
				className={`nvoos-cr-dropzone ${dragging ? 'nvoos-cr-dropzone--active' : ''} ${
					uploading ? 'nvoos-cr-dropzone--uploading' : ''
				}`}
				onDrop={handleDrop}
				onDragOver={handleDragOver}
				onDragLeave={handleDragLeave}
				onClick={() => !uploading && inputRef.current?.click()}
				onKeyDown={(e) => {
					if (!uploading && (e.key === 'Enter' || e.key === ' ')) {
						e.preventDefault();
						inputRef.current?.click();
					}
				}}
				role="button"
				tabIndex={0}
				aria-label={t('dropHint')}
			>
				{uploading ? (
					<div className="nvoos-cr-upload-progress">
						<div className="nvoos-cr-spinner" />
						<p>{progress}</p>
					</div>
				) : (
					<div className="nvoos-cr-dropzone-content">
						<div className="nvoos-cr-dropzone-icon">📁</div>
						<p>{t('dropHint')}</p>
						<p className="nvoos-cr-dropzone-formats">
							CBR, CBZ, CB7, CBT
						</p>
					</div>
				)}
				<input
					ref={inputRef}
					type="file"
					accept=".cbr,.cbz,.cb7,.cbt"
					onChange={handleFileSelect}
					className="nvoos-cr-file-input"
					aria-hidden="true"
				/>
			</div>

			{error && (
				<div className="nvoos-cr-upload-error" role="alert">
					<p>{error}</p>
				</div>
			)}

			<div className="nvoos-cr-upload-actions">
				<button
					className="nvoos-cr-btn"
					onClick={onCancel}
					disabled={uploading}
				>
					Cancel
				</button>
			</div>
		</div>
	);
}
