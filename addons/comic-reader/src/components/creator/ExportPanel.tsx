/**
 * NV oOS Comic Reader — Export Panel
 *
 * Export wizard for generated comics.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useCallback } from 'react';

interface ExportPanelProps {
	comicId: number;
}

type ExportFormat = 'cbz' | 'cbr';

export function ExportPanel({ comicId }: ExportPanelProps) {
	const [format, setFormat] = useState<ExportFormat>('cbz');
	const [exporting, setExporting] = useState(false);
	const [progress, setProgress] = useState(0);
	const [downloadUrl, setDownloadUrl] = useState<string | null>(null);
	const [error, setError] = useState<string | null>(null);

	const t = (key: string): string =>
		window.NVOOS_COMIC_READER?.i18n?.[key] || key;

	const handleExport = useCallback(async () => {
		setExporting(true);
		setError(null);
		setProgress(0);
		setDownloadUrl(null);

		try {
			const apiUrl = window.NVOOS_COMIC_READER?.apiUrl || '';
			const nonce = window.NVOOS_COMIC_READER?.nonce || '';

			// Simulate progress: start at 10%.
			setProgress(10);

			const response = await fetch(`${apiUrl}/creator/comics/${comicId}`, {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify({
					export_format: format,
				}),
			});

			setProgress(50);

			if (!response.ok) {
				const err = await response.json().catch(() => ({}));
				throw new Error(err.message || `HTTP ${response.status}`);
			}

			setProgress(80);

			// Poll for completion or use direct download URL from response.
			const data = await response.json();

			setProgress(100);

			if (data.export_url) {
				setDownloadUrl(data.export_url);
			} else {
				// Construct download URL pattern.
				const dlUrl = `${apiUrl}/creator/comics/${comicId}/export?format=${format}&_wpnonce=${nonce}`;
				setDownloadUrl(dlUrl);
			}
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorLoad'));
		} finally {
			setExporting(false);
		}
	}, [comicId, format, t]);

	return (
		<div className="nvoos-cr-export-panel" role="region" aria-label={t('exportPanel')}>
			<h2 className="nvoos-cr-creator-step-title">{t('exportPanel')}</h2>

			{error && (
				<div className="nvoos-cr-error" role="alert">
					<p>{error}</p>
				</div>
			)}

			<div className="nvoos-cr-export-form">
				<div className="nvoos-cr-form-group">
					<label htmlFor="nvoos-cr-export-format" className="nvoos-cr-label">
						{t('exportFormat')}
					</label>
					<select
						id="nvoos-cr-export-format"
						className="nvoos-cr-select"
						value={format}
						onChange={(e) => setFormat(e.target.value as ExportFormat)}
						disabled={exporting}
					>
						<option value="cbz">CBZ (ZIP)</option>
						<option value="cbr">CBR (RAR)</option>
					</select>
				</div>

				{exporting && (
					<div className="nvoos-cr-export-progress" role="progressbar" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100} aria-label={t('exportProgress')}>
						<div className="nvoos-cr-progress-bar">
							<div
								className="nvoos-cr-progress-fill"
								style={{ width: `${progress}%` }}
							/>
						</div>
						<span className="nvoos-cr-progress-label">{progress}%</span>
					</div>
				)}

				<div className="nvoos-cr-form-actions">
					<button
						className="nvoos-cr-btn nvoos-cr-btn-primary"
						onClick={handleExport}
						disabled={exporting}
						aria-label={t('exportComic')}
					>
						{exporting ? (
							<>
								<span className="nvoos-cr-spinner" aria-hidden="true" />
								{t('exporting')}
							</>
						) : (
							t('exportComic')
						)}
					</button>
				</div>

				{downloadUrl && !exporting && (
					<div className="nvoos-cr-export-result" role="status">
						<p className="nvoos-cr-export-success">
							{t('exportComplete')}
						</p>
						<a
							className="nvoos-cr-btn nvoos-cr-btn-primary"
							href={downloadUrl}
							download
							aria-label={t('downloadComic')}
						>
							{t('downloadComic')}
						</a>
					</div>
				)}
			</div>
		</div>
	);
}
