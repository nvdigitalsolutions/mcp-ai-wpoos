/**
 * NV oOS Comic Reader — App Shell
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useCallback } from 'react';
import { ComicLibrary } from './components/ComicLibrary';
import { ComixReader } from './components/ComixReader';
import { ComicUploader } from './components/ComicUploader';
import { ScriptEditor } from './components/creator/ScriptEditor';
import { CharacterSheet } from './components/creator/CharacterSheet';
import { StyleSelector } from './components/creator/StyleSelector';
import { PanelGenerator } from './components/creator/PanelGenerator';
import { ExportPanel } from './components/creator/ExportPanel';
import { createCreatorComic } from './api/comic-api';
import type { ComicItem } from './api/comic-api';

interface AppProps {
	initialConfig: {
		comicId: number;
		mode: 'library' | 'reader';
		height: string;
		direction: 'ltr' | 'rtl';
	};
}

type ViewMode = 'library' | 'reader' | 'upload' | 'creator';

const CREATOR_STEPS = [
	{ key: 'script', label: 'Script' },
	{ key: 'characters', label: 'Characters' },
	{ key: 'style', label: 'Style' },
	{ key: 'panels', label: 'Panels' },
	{ key: 'export', label: 'Export' },
] as const;

type CreatorStep = (typeof CREATOR_STEPS)[number]['key'];

export function App({ initialConfig }: AppProps) {
	const [viewMode, setViewMode] = useState<ViewMode>(
		initialConfig.comicId ? 'reader' : initialConfig.mode
	);
	const [activeComic, setActiveComic] = useState<ComicItem | null>(null);
	const [direction, setDirection] = useState<'ltr' | 'rtl'>(initialConfig.direction);
	const [refreshKey, setRefreshKey] = useState(0);

	// ─── Creator State ─────────────────────────────────────────
	const [creatorStep, setCreatorStep] = useState<CreatorStep>('script');
	const [activeComicId, setActiveComicId] = useState<number>(0);
	const [selectedStyle, setSelectedStyle] = useState<string>('manga');

	const handleOpenComic = useCallback((comic: ComicItem) => {
		setActiveComic(comic);
		setViewMode('reader');
	}, []);

	const handleBackToLibrary = useCallback(() => {
		setActiveComic(null);
		setViewMode('library');
		setRefreshKey((k) => k + 1);
		// Reset creator state.
		setActiveComicId(0);
		setCreatorStep('script');
	}, []);

	const handleUploadComplete = useCallback(() => {
		setViewMode('library');
		setRefreshKey((k) => k + 1);
	}, []);

	const handleToggleDirection = useCallback(() => {
		setDirection((d) => (d === 'ltr' ? 'rtl' : 'ltr'));
	}, []);

	// ─── Creator Handlers ──────────────────────────────────────

	const handleEnterCreator = useCallback(async () => {
		setViewMode('creator');
		setCreatorStep('script');

		try {
			const comic = await createCreatorComic({
				title: `New Comic — ${new Date().toLocaleDateString()}`,
			});
			setActiveComicId(comic.id);
		} catch {
			// If creation fails, still show creator — UI handles later.
			setActiveComicId(0);
		}
	}, []);

	const handleScriptCreated = useCallback((_scriptId: number) => {
		setCreatorStep('characters');
	}, []);

	const handleStyleSelect = useCallback((style: string) => {
		setSelectedStyle(style);
	}, []);

	const handleCreatorStepClick = useCallback((step: CreatorStep) => {
		// Allow navigating backwards freely; only allow forward if the step index
		// is within one of the current step.
		const currentIdx = CREATOR_STEPS.findIndex((s) => s.key === creatorStep);
		const clickedIdx = CREATOR_STEPS.findIndex((s) => s.key === step);
		if (clickedIdx <= currentIdx + 1) {
			setCreatorStep(step);
		}
	}, [creatorStep]);

	const t = (key: string): string => {
		const i18n = window.NVOOS_COMIC_READER?.i18n || {};
		return i18n[key] || key;
	};

	const renderCreatorStep = () => {
		if (!activeComicId) {
			return (
				<div className="nvoos-cr-empty" role="status">
					<div className="nvoos-cr-empty-icon">🖊️</div>
					<p>{t('creatingComic')}</p>
				</div>
			);
		}

		switch (creatorStep) {
			case 'script':
				return (
					<ScriptEditor
						comicId={activeComicId}
						onScriptCreated={handleScriptCreated}
					/>
				);
			case 'characters':
				return <CharacterSheet comicId={activeComicId} />;
			case 'style':
				return (
					<StyleSelector
						selected={selectedStyle}
						onSelect={handleStyleSelect}
					/>
				);
			case 'panels':
				return <PanelGenerator comicId={activeComicId} />;
			case 'export':
				return <ExportPanel comicId={activeComicId} />;
			default:
				return null;
		}
	};

	return (
		<div
			className="nvoos-cr-app"
			style={initialConfig.height ? { minHeight: initialConfig.height } : undefined}
			dir={direction}
		>
			<header className="nvoos-cr-toolbar">
				<h1 className="nvoos-cr-title">{t('library')}</h1>
				<div className="nvoos-cr-toolbar-actions">
					{viewMode === 'reader' && (
						<button
							className="nvoos-cr-btn"
							onClick={handleBackToLibrary}
							aria-label={t('library')}
						>
							← {t('library')}
						</button>
					)}
					{viewMode === 'reader' && (
						<button
							className="nvoos-cr-btn"
							onClick={handleToggleDirection}
							aria-label={direction === 'ltr' ? t('readingRtl') : t('readingLtr')}
						>
							{direction === 'ltr' ? t('readingRtl') : t('readingLtr')}
						</button>
					)}
					{viewMode === 'creator' && (
						<button
							className="nvoos-cr-btn"
							onClick={handleBackToLibrary}
							aria-label={t('library')}
						>
							← {t('library')}
						</button>
					)}
					<button
						className={`nvoos-cr-btn ${
							viewMode === 'creator' ? 'nvoos-cr-btn--active' : ''
						}`}
						onClick={handleEnterCreator}
						aria-label={t('create')}
					>
						+ Create
					</button>
					<button
						className="nvoos-cr-btn nvoos-cr-btn-primary"
						onClick={() => setViewMode('upload')}
						aria-label={t('dropHint')}
					>
						+ Upload
					</button>
				</div>
			</header>

			<main className="nvoos-cr-main">
				{viewMode === 'creator' && (
					<div className="nvoos-cr-creator" role="region" aria-label={t('comicCreator')}>
						{/* Step Progress Indicator */}
						<nav
							className="nvoos-cr-creator-steps"
							aria-label={t('creatorSteps')}
						>
							{CREATOR_STEPS.map((step, idx) => {
								const currentIdx = CREATOR_STEPS.findIndex(
									(s) => s.key === creatorStep
								);
								const isActive = step.key === creatorStep;
								const isCompleted = idx < currentIdx;
								const isClickable =
									idx <= currentIdx ||
									idx === currentIdx + 1;

								let stepClass = 'nvoos-cr-step-item';
								if (isActive) stepClass += ' nvoos-cr-step-item--active';
								if (isCompleted) stepClass += ' nvoos-cr-step-item--completed';

								return (
									<div key={step.key} className="nvoos-cr-step-wrapper">
										<button
											className={stepClass}
											onClick={() =>
												isClickable &&
												handleCreatorStepClick(step.key)
											}
											disabled={!isClickable}
											aria-current={isActive ? 'step' : undefined}
											aria-label={step.label}
										>
											<span className="nvoos-cr-step-number">
												{isCompleted ? '✓' : idx + 1}
											</span>
											<span className="nvoos-cr-step-label">
												{step.label}
											</span>
										</button>
										{idx < CREATOR_STEPS.length - 1 && (
											<div
												className={`nvoos-cr-step-connector ${
													isCompleted
														? 'nvoos-cr-step-connector--completed'
														: ''
												}`}
												aria-hidden="true"
											/>
										)}
									</div>
								);
							})}
						</nav>

						<div className="nvoos-cr-creator-content">
							{renderCreatorStep()}
						</div>

						{/* Next Step Navigation */}
						<div className="nvoos-cr-creator-footer">
							{creatorStep !== 'script' && (
								<button
									className="nvoos-cr-btn"
									onClick={() => {
										const currentIdx = CREATOR_STEPS.findIndex(
											(s) => s.key === creatorStep
										);
										if (currentIdx > 0) {
											setCreatorStep(
												CREATOR_STEPS[currentIdx - 1].key
											);
										}
									}}
									aria-label={t('previousStep')}
								>
									← {t('previous')}
								</button>
							)}
							<div />
							{creatorStep !== 'export' && (
								<button
									className="nvoos-cr-btn nvoos-cr-btn-primary"
									onClick={() => {
										const currentIdx = CREATOR_STEPS.findIndex(
											(s) => s.key === creatorStep
										);
										if (
											currentIdx <
											CREATOR_STEPS.length - 1
										) {
											setCreatorStep(
												CREATOR_STEPS[currentIdx + 1].key
											);
										}
									}}
									aria-label={t('nextStep')}
								>
									{t('next')} →
								</button>
							)}
						</div>
					</div>
				)}

				{viewMode === 'library' && (
					<ComicLibrary
						key={refreshKey}
						onOpenComic={handleOpenComic}
					/>
				)}
				{viewMode === 'reader' && activeComic && (
					<ComixReader comic={activeComic} direction={direction} />
				)}
				{viewMode === 'upload' && (
					<ComicUploader
						onComplete={handleUploadComplete}
						onCancel={() => setViewMode('library')}
					/>
				)}
			</main>
		</div>
	);
}
