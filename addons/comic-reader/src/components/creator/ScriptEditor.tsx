/**
 * NV oOS Comic Reader — Script Editor
 *
 * Script writing interface for comic creation.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useCallback } from 'react';
import { fetchCreatorComic } from '../../api/comic-api';
import type { CreatorScript, Scene } from '../../api/comic-api';

interface ScriptEditorProps {
	comicId: number;
	onScriptCreated: (scriptId: number) => void;
}

const GENRES = [
	'Action',
	'Adventure',
	'Comedy',
	'Drama',
	'Fantasy',
	'Horror',
	'Mystery',
	'Romance',
	'Sci-Fi',
	'Slice of Life',
	'Superhero',
	'Thriller',
];

export function ScriptEditor({ comicId, onScriptCreated }: ScriptEditorProps) {
	const [premise, setPremise] = useState('');
	const [genre, setGenre] = useState('Action');
	const [panelCount, setPanelCount] = useState(12);
	const [script, setScript] = useState<CreatorScript | null>(null);
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState<string | null>(null);

	const t = (key: string): string =>
		window.NVOOS_COMIC_READER?.i18n?.[key] || key;

	const handleGenerate = useCallback(async () => {
		if (!premise.trim()) {
			setError(t('scriptPremiseRequired'));
			return;
		}

		setLoading(true);
		setError(null);

		try {
			const apiUrl = window.NVOOS_COMIC_READER?.apiUrl || '';
			const nonce = window.NVOOS_COMIC_READER?.nonce || '';

			const response = await fetch(`${apiUrl}/creator/comics/${comicId}`, {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify({
					premise,
					genre,
					panel_count: panelCount,
				}),
			});

			if (!response.ok) {
				const err = await response.json().catch(() => ({}));
				throw new Error(err.message || `HTTP ${response.status}`);
			}

			const comic = await response.json();

			// Store script meta on the comic
			const scriptResponse = await fetch(
				`${apiUrl}/creator/comics/${comicId}`,
				{
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify({
						script_premise: premise,
						script_genre: genre,
						script_panel_count: panelCount,
					}),
				}
			);

			if (scriptResponse.ok) {
				setScript({
					id: comicId,
					title: comic.title || '',
					premise,
					genre,
					panel_count: panelCount,
					scenes: [],
				});
				onScriptCreated(comicId);
			}
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorLoad'));
		} finally {
			setLoading(false);
		}
	}, [premise, genre, panelCount, comicId, onScriptCreated, t]);

	const handleLoadScript = useCallback(async () => {
		setLoading(true);
		setError(null);
		try {
			const comic = await fetchCreatorComic(comicId);
			// @todo Replace the double-cast (as unknown as Record<string, unknown>)
			// with proper DTO hydration once the CreatorComic response type includes
			// script meta fields in the api layer.
			const meta = comic as unknown as Record<string, unknown>;
			setScript({
				id: comicId,
				title: comic.title,
				premise: (meta.script_premise as string) || '',
				genre: (meta.script_genre as string) || '',
				panel_count: (meta.script_panel_count as number) || 0,
				scenes: (meta.script_scenes as Scene[]) || [],
			});
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorLoad'));
		} finally {
			setLoading(false);
		}
	}, [comicId, t]);

	return (
		<div className="nvoos-cr-creator-script" role="region" aria-label={t('scriptEditor')}>
			<h2 className="nvoos-cr-creator-step-title">{t('scriptEditor')}</h2>

			<div className="nvoos-cr-script-form">
				<div className="nvoos-cr-form-group">
					<label htmlFor="nvoos-cr-premise" className="nvoos-cr-label">
						{t('premise')}
					</label>
					<textarea
						id="nvoos-cr-premise"
						className="nvoos-cr-textarea"
						value={premise}
						onChange={(e) => setPremise(e.target.value)}
						placeholder={t('premisePlaceholder')}
						rows={4}
						aria-required="true"
						disabled={loading}
					/>
				</div>

				<div className="nvoos-cr-form-row">
					<div className="nvoos-cr-form-group">
						<label htmlFor="nvoos-cr-genre" className="nvoos-cr-label">
							{t('genre')}
						</label>
						<select
							id="nvoos-cr-genre"
							className="nvoos-cr-select"
							value={genre}
							onChange={(e) => setGenre(e.target.value)}
							disabled={loading}
						>
							{GENRES.map((g) => (
								<option key={g} value={g}>
									{g}
								</option>
							))}
						</select>
					</div>

					<div className="nvoos-cr-form-group">
						<label htmlFor="nvoos-cr-panel-count" className="nvoos-cr-label">
							{t('panelCount')}
						</label>
						<input
							id="nvoos-cr-panel-count"
							className="nvoos-cr-input"
							type="number"
							min={1}
							max={100}
							value={panelCount}
							onChange={(e) => setPanelCount(parseInt(e.target.value, 10) || 1)}
							disabled={loading}
						/>
					</div>
				</div>

				<div className="nvoos-cr-form-actions">
					<button
						className="nvoos-cr-btn nvoos-cr-btn-primary"
						onClick={handleGenerate}
						disabled={loading || !premise.trim()}
						aria-label={t('generateScript')}
					>
						{loading ? (
							<>
								<span className="nvoos-cr-spinner" aria-hidden="true" />
								{t('generating')}
							</>
						) : (
							t('generateScript')
						)}
					</button>
					<button
						className="nvoos-cr-btn"
						onClick={handleLoadScript}
						disabled={loading}
						aria-label={t('loadScript')}
					>
						{t('loadScript')}
					</button>
				</div>
			</div>

			{error && (
				<div className="nvoos-cr-error" role="alert">
					<p>{error}</p>
				</div>
			)}

			{script && script.scenes.length > 0 && (
				<div className="nvoos-cr-scene-breakdown" role="list" aria-label={t('sceneBreakdown')}>
					<h3 className="nvoos-cr-scene-heading">{t('sceneBreakdown')}</h3>
					{script.scenes.map((scene: Scene, idx: number) => (
						<div key={idx} className="nvoos-cr-scene-card" role="listitem">
							<div className="nvoos-cr-scene-header">
								<span className="nvoos-cr-scene-number">
									{t('scene')} {scene.scene_number || idx + 1}
								</span>
							</div>
							{scene.description && (
								<p className="nvoos-cr-scene-desc">{scene.description}</p>
							)}
							{scene.setting && (
								<p className="nvoos-cr-scene-meta">
									<strong>{t('setting')}:</strong> {scene.setting}
								</p>
							)}
							{scene.characters && scene.characters.length > 0 && (
								<p className="nvoos-cr-scene-meta">
									<strong>{t('characters')}:</strong>{' '}
									{scene.characters.join(', ')}
								</p>
							)}
							{scene.dialogue && (
								<p className="nvoos-cr-scene-dialogue">
									<em>{scene.dialogue}</em>
								</p>
							)}
						</div>
					))}
				</div>
			)}

			{script && script.scenes.length === 0 && !loading && (
				<div className="nvoos-cr-empty" role="status">
					<p>{t('noScenes')}</p>
				</div>
			)}
		</div>
	);
}
