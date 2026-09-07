/**
 * NV oOS Comic Reader — Reader Settings Dialog
 *
 * Mirrors Komga's webreader settings: reading mode, scale type, background,
 * double pages, page transition, and touch gestures. Changes are persisted
 * through the prefs store.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.3.0
 */

import { useEffect, useRef } from 'react';
import type {
	ReaderBackground,
	ReaderPrefs,
	ReadingMode,
	ScaleType,
	PageTransition,
} from '../types/reader-prefs';
import { t } from '../utils/i18n';

interface ReaderSettingsProps {
	prefs: ReaderPrefs;
	onChange: (prefs: ReaderPrefs) => void;
	onClose: () => void;
}

interface Option<T extends string> {
	value: T;
	labelKey: string;
}

const MODES: Option<ReadingMode>[] = [
	{ value: 'paged', labelKey: 'modePaged' },
	{ value: 'scroll', labelKey: 'modeScroll' },
	{ value: 'webtoon', labelKey: 'modeWebtoon' },
];

const SCALES: Option<ScaleType>[] = [
	{ value: 'fit-screen', labelKey: 'fitScreen' },
	{ value: 'fit-width', labelKey: 'fitWidth' },
	{ value: 'fit-height', labelKey: 'fitHeight' },
	{ value: 'none', labelKey: 'original' },
];

const BACKGROUNDS: Option<ReaderBackground>[] = [
	{ value: 'white', labelKey: 'backgroundWhite' },
	{ value: 'gray', labelKey: 'backgroundGray' },
	{ value: 'black', labelKey: 'backgroundBlack' },
];

const TRANSITIONS: Option<PageTransition>[] = [
	{ value: 'none', labelKey: 'transitionNone' },
	{ value: 'fade', labelKey: 'transitionFade' },
	{ value: 'slide', labelKey: 'transitionSlide' },
];

function SelectRow<T extends string>({
	label,
	value,
	options,
	onChange,
}: {
	label: string;
	value: T;
	options: Option<T>[];
	onChange: (value: T) => void;
}) {
	const id = `nvoos-cr-setting-${value.length ? options[0]?.value ?? '' : ''}-${label}`;
	return (
		<div className="nvoos-cr-settings-row">
			<label className="nvoos-cr-settings-label" htmlFor={id}>
				{label}
			</label>
			<select
				id={id}
				className="nvoos-cr-settings-select"
				value={value}
				onChange={(e) => onChange(e.target.value as T)}
			>
				{options.map((option) => (
					<option key={option.value} value={option.value}>
						{t(option.labelKey)}
					</option>
				))}
			</select>
		</div>
	);
}

export function ReaderSettings({
	prefs,
	onChange,
	onClose,
}: ReaderSettingsProps) {
	const dialogRef = useRef<HTMLDivElement>(null);

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

	const update = (patch: Partial<ReaderPrefs>) => {
		onChange({ ...prefs, ...patch });
	};

	return (
		<div className="nvoos-cr-overlay" role="dialog" aria-modal="true" aria-label={t('settings')}>
			<div className="nvoos-cr-overlay-header">
				<h2 className="nvoos-cr-overlay-title">{t('settings')}</h2>
				<button className="nvoos-cr-btn" onClick={onClose} aria-label={t('close')}>
					×
				</button>
			</div>
			<div className="nvoos-cr-settings" ref={dialogRef} tabIndex={-1}>
				<SelectRow<ReadingMode>
					label={t('readingMode')}
					value={prefs.readingMode}
					options={MODES}
					onChange={(readingMode) => update({ readingMode })}
				/>

				{prefs.readingMode === 'paged' && (
					<>
						<SelectRow<ScaleType>
							label={t('scaleType')}
							value={prefs.scale}
							options={SCALES}
							onChange={(scale) => update({ scale })}
						/>
						<div className="nvoos-cr-settings-row">
							<label className="nvoos-cr-settings-label">
								{t('doublePage')}
							</label>
							<input
								type="checkbox"
								checked={prefs.doublePage}
								onChange={(e) =>
									update({ doublePage: e.target.checked })
								}
							/>
						</div>
						<SelectRow<PageTransition>
							label={t('transitions')}
							value={prefs.transition}
							options={TRANSITIONS}
							onChange={(transition) => update({ transition })}
						/>
					</>
				)}

				<SelectRow<ReaderBackground>
					label={t('background')}
					value={prefs.background}
					options={BACKGROUNDS}
					onChange={(background) => update({ background })}
				/>

				<div className="nvoos-cr-settings-row">
					<label className="nvoos-cr-settings-label">
						{t('gestures')}
					</label>
					<input
						type="checkbox"
						checked={prefs.gestures}
						onChange={(e) => update({ gestures: e.target.checked })}
					/>
				</div>
			</div>
		</div>
	);
}
