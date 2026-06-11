/**
 * NV oOS Comic Reader — Character Sheet
 *
 * Character creation and management interface.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useEffect, useCallback } from 'react';
import { fetchCharacters } from '../../api/comic-api';

interface CharacterSheetProps {
	comicId: number;
}

interface Character {
	id?: number;
	name: string;
	description: string;
	style_notes: string;
	role: string;
	reference_image?: string;
}

const ROLES = [
	'Protagonist',
	'Antagonist',
	'Deuteragonist',
	'Sidekick',
	'Mentor',
	'Love Interest',
	'Villain',
	'Supporting',
	'Cameo',
	'Custom',
];

const EMPTY_CHARACTER: Character = {
	name: '',
	description: '',
	style_notes: '',
	role: 'Protagonist',
};

export function CharacterSheet({ comicId }: CharacterSheetProps) {
	const [characters, setCharacters] = useState<Character[]>([]);
	const [form, setForm] = useState<Character>({ ...EMPTY_CHARACTER });
	const [creating, setCreating] = useState(false);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [generatingRefs, setGeneratingRefs] = useState<Set<number>>(new Set());

	const t = (key: string): string =>
		window.NVOOS_COMIC_READER?.i18n?.[key] || key;

	const loadCharacters = useCallback(async () => {
		setLoading(true);
		setError(null);
		try {
			const data = await fetchCharacters(comicId);
			// @todo Replace the double-cast (as unknown as Character[]) with a
			// proper type guard or DTO hydration once the REST API response type
			// is defined in the api layer.
			setCharacters(data.characters as unknown as Character[]);
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorLoad'));
		} finally {
			setLoading(false);
		}
	}, [comicId, t]);

	useEffect(() => {
		loadCharacters();
	}, [loadCharacters]);

	const handleCreate = useCallback(async () => {
		if (!form.name.trim()) {
			setError(t('characterNameRequired'));
			return;
		}

		setCreating(true);
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
					add_character: form,
				}),
			});

			if (!response.ok) {
				const err = await response.json().catch(() => ({}));
				throw new Error(err.message || `HTTP ${response.status}`);
			}

			setForm({ ...EMPTY_CHARACTER });
			await loadCharacters();
		} catch (err) {
			setError(err instanceof Error ? err.message : t('errorLoad'));
		} finally {
			setCreating(false);
		}
	}, [form, comicId, loadCharacters, t]);

	const handleGenerateRef = useCallback(
		async (index: number) => {
			setGeneratingRefs((prev) => new Set(prev).add(index));
			setError(null);

			try {
				const apiUrl = window.NVOOS_COMIC_READER?.apiUrl || '';
				const nonce = window.NVOOS_COMIC_READER?.nonce || '';
				const character = characters[index];

				const response = await fetch(`${apiUrl}/creator/comics/${comicId}`, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify({
						generate_character_ref: character.name,
					}),
				});

				if (!response.ok) {
					const err = await response.json().catch(() => ({}));
					throw new Error(err.message || `HTTP ${response.status}`);
				}

				await loadCharacters();
			} catch (err) {
				setError(err instanceof Error ? err.message : t('errorLoad'));
			} finally {
				setGeneratingRefs((prev) => {
					const next = new Set(prev);
					next.delete(index);
					return next;
				});
			}
		},
		[comicId, characters, loadCharacters, t]
	);

	if (loading) {
		return (
			<div className="nvoos-cr-loading" role="status">
				<div className="nvoos-cr-spinner" />
				<span>{t('loading')}</span>
			</div>
		);
	}

	return (
		<div className="nvoos-cr-character-sheet" role="region" aria-label={t('characterSheet')}>
			<h2 className="nvoos-cr-creator-step-title">{t('characterSheet')}</h2>

			{error && (
				<div className="nvoos-cr-error" role="alert">
					<p>{error}</p>
				</div>
			)}

			<div className="nvoos-cr-character-form">
				<div className="nvoos-cr-form-group">
					<label htmlFor="nvoos-cr-char-name" className="nvoos-cr-label">
						{t('characterName')}
					</label>
					<input
						id="nvoos-cr-char-name"
						className="nvoos-cr-input"
						type="text"
						value={form.name}
						onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
						placeholder={t('characterNamePlaceholder')}
						disabled={creating}
						aria-required="true"
					/>
				</div>

				<div className="nvoos-cr-form-group">
					<label htmlFor="nvoos-cr-char-desc" className="nvoos-cr-label">
						{t('characterDescription')}
					</label>
					<textarea
						id="nvoos-cr-char-desc"
						className="nvoos-cr-textarea"
						value={form.description}
						onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
						placeholder={t('characterDescPlaceholder')}
						rows={3}
						disabled={creating}
					/>
				</div>

				<div className="nvoos-cr-form-row">
					<div className="nvoos-cr-form-group">
						<label htmlFor="nvoos-cr-char-style" className="nvoos-cr-label">
							{t('styleNotes')}
						</label>
						<input
							id="nvoos-cr-char-style"
							className="nvoos-cr-input"
							type="text"
							value={form.style_notes}
							onChange={(e) =>
								setForm((f) => ({ ...f, style_notes: e.target.value }))
							}
							placeholder={t('styleNotesPlaceholder')}
							disabled={creating}
						/>
					</div>

					<div className="nvoos-cr-form-group">
						<label htmlFor="nvoos-cr-char-role" className="nvoos-cr-label">
							{t('role')}
						</label>
						<select
							id="nvoos-cr-char-role"
							className="nvoos-cr-select"
							value={form.role}
							onChange={(e) =>
								setForm((f) => ({ ...f, role: e.target.value }))
							}
							disabled={creating}
						>
							{ROLES.map((r) => (
								<option key={r} value={r}>
									{r}
								</option>
							))}
						</select>
					</div>
				</div>

				<div className="nvoos-cr-form-actions">
					<button
						className="nvoos-cr-btn nvoos-cr-btn-primary"
						onClick={handleCreate}
						disabled={creating || !form.name.trim()}
						aria-label={t('addCharacter')}
					>
						{creating ? (
							<>
								<span className="nvoos-cr-spinner" aria-hidden="true" />
								{t('adding')}
							</>
						) : (
							t('addCharacter')
						)}
					</button>
				</div>
			</div>

			{characters.length === 0 ? (
				<div className="nvoos-cr-empty" role="status">
					<div className="nvoos-cr-empty-icon">👤</div>
					<p>{t('noCharacters')}</p>
					<p className="nvoos-cr-empty-hint">{t('noCharactersHint')}</p>
				</div>
			) : (
				<div className="nvoos-cr-character-list" role="list" aria-label={t('characters')}>
					{characters.map((character, idx) => {
						const isRefGenerating = generatingRefs.has(idx);
						return (
							<div
								key={character.id || idx}
								className="nvoos-cr-character-card"
								role="listitem"
							>
								<div className="nvoos-cr-character-ref">
									{character.reference_image ? (
										<img
											src={character.reference_image}
											alt={character.name}
											className="nvoos-cr-ref-image"
											loading="lazy"
										/>
									) : (
										<div className="nvoos-cr-ref-placeholder">
											<span>👤</span>
										</div>
									)}
									{isRefGenerating && (
										<div className="nvoos-cr-ref-overlay" aria-hidden="true">
											<div className="nvoos-cr-spinner" />
										</div>
									)}
								</div>
								<div className="nvoos-cr-character-info">
									<h3 className="nvoos-cr-character-name">{character.name}</h3>
									<span className="nvoos-cr-character-role">{character.role}</span>
									{character.description && (
										<p className="nvoos-cr-character-desc">
											{character.description}
										</p>
									)}
									{character.style_notes && (
										<p className="nvoos-cr-character-style">
											<em>{character.style_notes}</em>
										</p>
									)}
								</div>
								<button
									className="nvoos-cr-btn"
									onClick={() => handleGenerateRef(idx)}
									disabled={isRefGenerating}
									aria-label={`${t('generateRef')} ${character.name}`}
								>
									{isRefGenerating ? '…' : t('generateRef')}
								</button>
							</div>
						);
					})}
				</div>
			)}
		</div>
	);
}
