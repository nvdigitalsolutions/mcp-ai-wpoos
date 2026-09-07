/**
 * NV oOS Comic Reader — Metadata Editor
 *
 * Dialog for editing a comic's details: title, series, issue number, volume,
 * writer, publisher, and reading direction. Mirrors Komga's book metadata
 * editor on WordPress attachment primitives.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.4.0
 */

import { useEffect, useRef, useState } from 'react';
import type { ComicItem } from '../api/comic-api';
import { updateComicDetails } from '../api/comic-api';
import { t } from '../utils/i18n';

interface MetadataEditorProps {
	comic: ComicItem;
	onClose: () => void;
	onSaved: (updated: ComicItem) => void;
}

export function MetadataEditor({
	comic,
	onClose,
	onSaved,
}: MetadataEditorProps) {
	const dialogRef = useRef<HTMLDivElement>(null);
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const [form, setForm] = useState(() => ({
		title: comic.title,
		series: comic.series[0] ?? comic.metadata.series ?? '',
		number: comic.metadata.number ?? '',
		volume: comic.metadata.volume ?? '',
		writer: comic.metadata.writer ?? '',
		publisher: comic.metadata.publisher ?? '',
		reading_direction: comic.reading_direction ?? 'ltr',
	}));

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

	const setField = (field: string, value: string) => {
		setForm((f) => ({ ...f, [field]: value }));
	};

	const handleSave = async () => {
		setSaving(true);
		setError(null);
		try {
			const updated = await updateComicDetails(comic.id, {
				title: form.title,
				series: form.series,
				number: form.number,
				volume: form.volume,
				writer: form.writer,
				publisher: form.publisher,
				reading_direction: form.reading_direction as 'ltr' | 'rtl',
			});
			onSaved(updated);
			onClose();
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorSave'));
		} finally {
			setSaving(false);
		}
	};

	const fields: Array<{ key: string; labelKey: string; type?: string }> = [
		{ key: 'title', labelKey: 'title' },
		{ key: 'series', labelKey: 'series' },
		{ key: 'number', labelKey: 'number' },
		{ key: 'volume', labelKey: 'volume' },
		{ key: 'writer', labelKey: 'writer' },
		{ key: 'publisher', labelKey: 'publisher' },
	];

	return (
		<div className="nvoos-cr-overlay" role="dialog" aria-modal="true" aria-label={t('editMetadata')}>
			<div className="nvoos-cr-overlay-header">
				<h2 className="nvoos-cr-overlay-title">{t('editMetadata')}</h2>
				<button className="nvoos-cr-btn" onClick={onClose} aria-label={t('close')}>
					×
				</button>
			</div>
			<div className="nvoos-cr-settings" ref={dialogRef} tabIndex={-1}>
				{fields.map((field) => (
					<div className="nvoos-cr-settings-row" key={field.key}>
						<label
							className="nvoos-cr-settings-label"
							htmlFor={`nvoos-cr-edit-${field.key}`}
						>
							{t(field.labelKey)}
						</label>
						<input
							id={`nvoos-cr-edit-${field.key}`}
							className="nvoos-cr-settings-select"
							type="text"
							value={form[field.key as keyof typeof form] as string}
							onChange={(e) => setField(field.key, e.target.value)}
						/>
					</div>
				))}

				<div className="nvoos-cr-settings-row">
					<label className="nvoos-cr-settings-label" htmlFor="nvoos-cr-edit-direction">
						{t('readingLtr')} / {t('readingRtl')}
					</label>
					<select
						id="nvoos-cr-edit-direction"
						className="nvoos-cr-settings-select"
						value={form.reading_direction}
						onChange={(e) => setField('reading_direction', e.target.value)}
					>
						<option value="ltr">{t('readingLtr')}</option>
						<option value="rtl">{t('readingRtl')}</option>
					</select>
				</div>

				{error && (
					<div className="nvoos-cr-upload-error" role="alert">
						<p>{error}</p>
					</div>
				)}

				<div className="nvoos-cr-settings-actions">
					<button className="nvoos-cr-btn" onClick={onClose} disabled={saving}>
						{t('cancel')}
					</button>
					<button
						className="nvoos-cr-btn nvoos-cr-btn-primary"
						onClick={handleSave}
						disabled={saving}
					>
						{saving ? '…' : t('save')}
					</button>
				</div>
			</div>
		</div>
	);
}
