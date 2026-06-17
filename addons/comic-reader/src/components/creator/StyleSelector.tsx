/**
 * NV oOS Comic Reader — Style Selector
 *
 * Visual art style picker for comic creation.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useEffect } from 'react';
import { fetchCreatorStyles } from '../../api/comic-api';
import type { CreatorStyle } from '../../api/comic-api';

interface StyleSelectorProps {
	selected: string;
	onSelect: (style: string) => void;
}

export function StyleSelector({ selected, onSelect }: StyleSelectorProps) {
	const [styles, setStyles] = useState<CreatorStyle[]>([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);

	const t = (key: string): string =>
		window.NVOOS_COMIC_READER?.i18n?.[key] || key;

	useEffect(() => {
		let cancelled = false;
		async function loadStyles() {
			setLoading(true);
			setError(null);
			try {
				const data = await fetchCreatorStyles();
				if (!cancelled) {
					setStyles(data);
				}
			} catch (err) {
				if (!cancelled) {
					setError(err instanceof Error ? err.message : t('errorLoad'));
				}
			} finally {
				if (!cancelled) {
					setLoading(false);
				}
			}
		}
		loadStyles();
		return () => {
			cancelled = true;
		};
	}, [t]);

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
			</div>
		);
	}

	return (
		<div
			className="nvoos-cr-style-selector"
			role="region"
			aria-label={t('styleSelector')}
		>
			<h2 className="nvoos-cr-creator-step-title">{t('styleSelector')}</h2>

			<div className="nvoos-cr-style-grid" role="radiogroup" aria-label={t('artStyle')}>
				{styles.map((style) => (
					<button
						key={style.slug}
						className={`nvoos-cr-style-card ${
							selected === style.slug ? 'nvoos-cr-style-card--selected' : ''
						}`}
						onClick={() => onSelect(style.slug)}
						role="radio"
						aria-checked={selected === style.slug}
						aria-label={style.name}
					>
						<div className="nvoos-cr-style-preview">
							<span className="nvoos-cr-style-icon">🎨</span>
						</div>
						<div className="nvoos-cr-style-info">
							<h3 className="nvoos-cr-style-name">{style.name}</h3>
							<p className="nvoos-cr-style-desc">{style.description}</p>
						</div>
						{selected === style.slug && (
							<div className="nvoos-cr-style-check" aria-hidden="true">
								✓
							</div>
						)}
					</button>
				))}
			</div>
		</div>
	);
}
