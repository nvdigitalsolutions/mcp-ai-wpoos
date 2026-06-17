/**
 * NV oOS Comic Reader — Panel Generator
 *
 * Panel gallery and generation interface.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useEffect, useCallback } from 'react';
import { fetchPanels, generatePanels } from '../../api/comic-api';
import type { ComicPanel } from '../../api/comic-api';

interface PanelGeneratorProps {
	comicId: number;
}

export function PanelGenerator({ comicId }: PanelGeneratorProps) {
	const [panels, setPanels] = useState<ComicPanel[]>([]);
	const [loading, setLoading] = useState(true);
	const [generating, setGenerating] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const [generatingIds, setGeneratingIds] = useState<Set<number>>(new Set());

	const t = (key: string): string =>
		window.NVOOS_COMIC_READER?.i18n?.[key] || key;

	const loadPanels = useCallback(async () => {
		setLoading(true);
		setError(null);
		try {
			const data = await fetchPanels(comicId);
			setPanels(data.panels);
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorLoad'));
		} finally {
			setLoading(false);
		}
	}, [comicId, t]);

	useEffect(() => {
		loadPanels();
	}, [loadPanels]);

	const handleGenerateAll = useCallback(async () => {
		setGenerating(true);
		setError(null);
		try {
			await generatePanels(comicId);
			await loadPanels();
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorLoad'));
		} finally {
			setGenerating(false);
		}
	}, [comicId, loadPanels, t]);

	const handleRegenerate = useCallback(
		async (panelIdx: number) => {
			setGeneratingIds((prev) => new Set(prev).add(panelIdx));
			setError(null);
			try {
				await generatePanels(comicId, [panelIdx]);
				await loadPanels();
			} catch (err) {
				setError(err instanceof Error ? err.message : t('errorLoad'));
			} finally {
				setGeneratingIds((prev) => {
					const next = new Set(prev);
					next.delete(panelIdx);
					return next;
				});
			}
		},
		[comicId, loadPanels, t]
	);

	if (loading) {
		return (
			<div className="nvoos-cr-loading" role="status">
				<div className="nvoos-cr-spinner" />
				<span>{t('loading')}</span>
			</div>
		);
	}

	if (error) {
		return (
			<div className="nvoos-cr-error" role="alert">
				<p>{error}</p>
				<button className="nvoos-cr-btn" onClick={loadPanels}>
					Retry
				</button>
			</div>
		);
	}

	return (
		<div className="nvoos-cr-panel-generator" role="region" aria-label={t('panelGenerator')}>
			<div className="nvoos-cr-panel-header">
				<h2 className="nvoos-cr-creator-step-title">{t('panelGenerator')}</h2>
				<div className="nvoos-cr-panel-actions">
					{generating && (
						<div className="nvoos-cr-generating-indicator" role="status" aria-live="polite">
							<div className="nvoos-cr-spinner" aria-hidden="true" />
							<span>{t('generatingPanels')}</span>
						</div>
					)}
					<button
						className="nvoos-cr-btn nvoos-cr-btn-primary"
						onClick={handleGenerateAll}
						disabled={generating}
						aria-label={t('generateAll')}
					>
						{generating ? t('generating') : t('generateAll')}
					</button>
				</div>
			</div>

			{panels.length === 0 ? (
				<div className="nvoos-cr-empty" role="status">
					<div className="nvoos-cr-empty-icon">🖼️</div>
					<p>{t('noPanels')}</p>
					<p className="nvoos-cr-empty-hint">{t('noPanelsHint')}</p>
				</div>
			) : (
				<div className="nvoos-cr-panel-grid" role="list" aria-label={t('panels')}>
					{panels.map((panel, idx) => {
						const isGenerating = generatingIds.has(idx);
						return (
							<div
								key={panel.id || idx}
								className="nvoos-cr-panel-card"
								role="listitem"
							>
								<div className="nvoos-cr-panel-preview">
									{panel.image_url ? (
										<img
											src={panel.image_url}
											alt={`${t('panel')} ${panel.page}:${panel.panel}`}
											loading="lazy"
										/>
									) : (
										<div className="nvoos-cr-panel-placeholder">
											<span className="nvoos-cr-panel-label">
												{t('page')} {panel.page}:{t('panel')} {panel.panel}
											</span>
										</div>
									)}
									{isGenerating && (
										<div className="nvoos-cr-panel-overlay" aria-hidden="true">
											<div className="nvoos-cr-spinner" />
										</div>
									)}
								</div>
								<div className="nvoos-cr-panel-info">
									<span className="nvoos-cr-panel-id">
										{t('page')} {panel.page} &middot; {t('panel')} {panel.panel}
									</span>
									{panel.description && (
										<p className="nvoos-cr-panel-desc">{panel.description}</p>
									)}
									{panel.status && (
										<span className="nvoos-cr-panel-status">
											{panel.status}
										</span>
									)}
								</div>
								<button
									className="nvoos-cr-btn nvoos-cr-panel-regenerate"
									onClick={() => handleRegenerate(idx)}
									disabled={generating || isGenerating}
									aria-label={`${t('regenerate')} ${t('panel')} ${panel.page}:${panel.panel}`}
								>
									{isGenerating ? '…' : t('regenerate')}
								</button>
							</div>
						);
					})}
				</div>
			)}
		</div>
	);
}
